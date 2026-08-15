<?php

namespace App\Actions\Sales;

use App\Enums\InventoryBehavior;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\ProductItemType;
use App\Enums\SaleStatus;
use App\Enums\SaleStockMovementKind;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemComponent;
use App\Models\SalePayment;
use App\Models\SaleStockMovement;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConfirmSale
{
    public function __construct(
        private InventoryService $inventory,
        private CompanyAccess $access,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: int|float|string}>  $lines
     * @param  array<int, array{payment_method: PaymentMethod, amount_base: int|float|string}>  $payments
     */
    public function handle(
        Membership $actor,
        Branch $branch,
        Warehouse $warehouse,
        array $lines,
        array $payments,
        ?string $customerName = null,
        ?CarbonInterface $occurredAt = null,
    ): Sale {
        $this->authorize($actor);
        $this->validateLocation($actor, $branch, $warehouse);

        if ($lines === [] || $payments === []) {
            throw new DomainException('La venta requiere al menos un ramo y un pago.');
        }

        return DB::transaction(function () use (
            $actor, $branch, $warehouse, $lines, $payments, $customerName, $occurredAt,
        ): Sale {
            $company = Company::query()->whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $lockedActor = Membership::query()
                ->withoutGlobalScope('company')
                ->whereKey($actor->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedActor->status !== MembershipStatus::Active) {
                throw new DomainException('La membership responsable no está activa.');
            }

            $this->authorize($lockedActor);
            $preparedItems = $this->prepareItems($company, $warehouse, $lines);
            $total = $this->sumMoney($preparedItems->pluck('subtotal_base'));
            $preparedPayments = $this->preparePayments($company, $payments, $total);
            $totalCost = $this->sumMoney($preparedItems->pluck('total_cost_base'));
            $sequence = (int) Sale::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->max('sequence_number') + 1;
            $number = 'V-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            $sale = Sale::query()->create([
                'company_id' => $company->getKey(),
                'sequence_number' => $sequence,
                'number' => $number,
                'branch_id' => $branch->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'customer_id' => null,
                'customer_name' => $this->normalizeCustomerName($customerName),
                'branch_name' => $branch->name,
                'warehouse_name' => $warehouse->name,
                'status' => SaleStatus::Confirmed,
                'subtotal_base' => $total,
                'total_base' => $total,
                'total_cost_base' => $totalCost,
                'gross_margin_base' => $this->money(bcsub($total, $totalCost, 8)),
                'confirmed_by_membership_id' => $lockedActor->getKey(),
                'occurred_at' => $occurredAt ?? now(),
            ]);

            foreach ($preparedItems as $preparedItem) {
                $item = SaleItem::query()->create([
                    'company_id' => $company->getKey(),
                    'sale_id' => $sale->getKey(),
                    ...$preparedItem->except('components')->all(),
                ]);

                foreach ($preparedItem['components'] as $component) {
                    SaleItemComponent::query()->create([
                        'company_id' => $company->getKey(),
                        'sale_item_id' => $item->getKey(),
                        ...$component,
                    ]);
                }
            }

            foreach ($preparedPayments as $payment) {
                SalePayment::query()->create([
                    'company_id' => $company->getKey(),
                    'sale_id' => $sale->getKey(),
                    ...$payment,
                ]);
            }

            $movementLines = $this->movementLines($preparedItems);
            $movement = $this->inventory->record(
                $lockedActor,
                $warehouse,
                StockMovementType::Sale,
                $movementLines,
                "Venta {$number}",
                $occurredAt,
            );

            SaleStockMovement::query()->create([
                'company_id' => $company->getKey(),
                'sale_id' => $sale->getKey(),
                'stock_movement_id' => $movement->getKey(),
                'kind' => SaleStockMovementKind::Consumption,
            ]);

            return $sale->load([
                'branch', 'warehouse', 'confirmedBy.user', 'items.components',
                'payments.paymentMethod', 'stockMovementLinks.stockMovement.lines.product',
            ]);
        }, attempts: 3);
    }

    private function authorize(Membership $actor): void
    {
        if (! $this->access->allows($actor->user, $actor->company, 'sales.create')
            || ! $this->access->moduleEnabled($actor->company, ModuleCode::Inventory)) {
            throw new DomainException('La membership responsable no puede registrar ventas.');
        }
    }

    private function validateLocation(Membership $actor, Branch $branch, Warehouse $warehouse): void
    {
        if ($branch->company_id !== $actor->company_id
            || $warehouse->company_id !== $actor->company_id
            || $warehouse->branch_id !== $branch->getKey()
            || ! $branch->is_active
            || ! $warehouse->is_active) {
            throw new DomainException('La sucursal y el almacén deben estar activos y pertenecer a la empresa.');
        }
    }

    /**
     * @param  array<int, array{product: Product, quantity: int|float|string}>  $lines
     * @return Collection<int, Collection<string, mixed>>
     */
    private function prepareItems(Company $company, Warehouse $warehouse, array $lines): Collection
    {
        $requested = collect($lines);
        $productIds = $requested->map(fn (array $line): int => (int) $line['product']->getKey());

        if ($productIds->duplicates()->isNotEmpty()) {
            throw new DomainException('Cada ramo debe aparecer una sola vez en la venta.');
        }

        $quantities = $requested->mapWithKeys(function (array $line): array {
            $quantity = Decimal::normalize($line['quantity'], 6);

            if (bccomp($quantity, '0', 6) <= 0) {
                throw new DomainException('La cantidad vendida debe ser mayor que cero.');
            }

            return [(int) $line['product']->getKey() => $quantity];
        });
        $products = Product::query()
            ->withoutGlobalScope('company')
            ->with('unit:id,symbol')
            ->where('company_id', $company->getKey())
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw new DomainException('Todos los ramos deben pertenecer a la empresa.');
        }

        $recipes = ProductRecipe::query()
            ->withoutGlobalScope('company')
            ->with(['items.componentProduct.unit:id,symbol'])
            ->where('company_id', $company->getKey())
            ->whereIn('product_id', $productIds)
            ->where('active_slot', 1)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
        $componentIds = $recipes->flatMap(
            fn (ProductRecipe $recipe) => $recipe->items->pluck('component_product_id'),
        )->unique()->sort()->values();
        $balances = StockBalance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->whereIn('product_id', $componentIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
        $requirements = [];
        $prepared = collect();

        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            $recipe = $recipes->get($productId);

            if (! $product instanceof Product
                || ! $product->is_active
                || ! $product->is_sellable
                || $product->item_type !== ProductItemType::Physical
                || $product->inventory_behavior !== InventoryBehavior::Components
                || ! $recipe instanceof ProductRecipe) {
                throw new DomainException("{$product?->name} no es un ramo vendible con composición activa.");
            }

            $quantity = $quantities->get($productId);
            $subtotal = $this->money(bcmul($quantity, $product->sale_price_base, 8));
            $components = collect();
            $itemCost = '0.0000';

            foreach ($recipe->items as $recipeItem) {
                $component = $recipeItem->componentProduct;
                $perUnit = bcdiv($recipeItem->quantity, $recipe->yield_quantity, 12);
                $wasteMultiplier = bcadd('1', bcdiv($recipeItem->waste_percentage, '100', 12), 12);
                $consumed = $this->quantity(bcmul(bcmul($perUnit, $wasteMultiplier, 12), $quantity, 12));
                $balance = $balances->get($component->getKey());
                $unitCost = $this->inventory->currentUnitCost($component, $balance);

                if ($unitCost === null) {
                    throw new DomainException("No existe un costo conocido para {$component->name}.");
                }

                $componentCost = $this->money(bcmul($consumed, $unitCost, 8));
                $itemCost = $this->money(bcadd($itemCost, $componentCost, 8));
                $componentId = (int) $component->getKey();
                $requirements[$componentId] = [
                    'product' => $component,
                    'quantity' => isset($requirements[$componentId])
                        ? bcadd($requirements[$componentId]['quantity'], $consumed, 6)
                        : $consumed,
                ];
                $components->push([
                    'product_id' => $componentId,
                    'product_name' => $component->name,
                    'product_sku' => $component->sku,
                    'unit_symbol' => $component->unit->symbol,
                    'recipe_quantity' => $recipeItem->quantity,
                    'waste_percentage' => $recipeItem->waste_percentage,
                    'quantity_consumed' => $consumed,
                    'unit_cost_base' => $this->money($unitCost),
                    'total_cost_base' => $componentCost,
                ]);
            }

            $prepared->push(collect([
                'product_id' => $product->getKey(),
                'product_recipe_id' => $recipe->getKey(),
                'recipe_version' => $recipe->version,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'unit_symbol' => $product->unit->symbol,
                'quantity' => $quantity,
                'unit_price_base' => $product->sale_price_base,
                'subtotal_base' => $subtotal,
                'unit_cost_base' => $this->money(bcdiv($itemCost, $quantity, 8)),
                'total_cost_base' => $itemCost,
                'gross_margin_base' => $this->money(bcsub($subtotal, $itemCost, 8)),
                'components' => $components,
            ]));
        }

        $shortages = [];

        foreach ($requirements as $componentId => $requirement) {
            $available = $balances->has($componentId)
                ? $balances->get($componentId)->quantity
                : '0.000000';

            if (bccomp($available, $requirement['quantity'], 6) < 0) {
                $missing = bcsub($requirement['quantity'], $available, 6);
                $shortages[] = "No hay suficientes {$requirement['product']->name} para preparar esta venta. "
                    ."Necesitas: {$requirement['quantity']}. Disponible: {$available}. Faltan: {$missing}.";
            }
        }

        if ($shortages !== []) {
            throw new DomainException(implode("\n", $shortages));
        }

        return $prepared;
    }

    /**
     * @param  array<int, array{payment_method: PaymentMethod, amount_base: int|float|string}>  $payments
     * @param  numeric-string  $total
     * @return array<int, array{payment_method_id: int, payment_method_name: string, amount_base: numeric-string}>
     */
    private function preparePayments(Company $company, array $payments, string $total): array
    {
        $requested = collect($payments);
        $methodIds = $requested->map(fn (array $payment): int => (int) $payment['payment_method']->getKey());

        if ($methodIds->duplicates()->isNotEmpty()) {
            throw new DomainException('Cada método de pago debe aparecer una sola vez.');
        }

        $methods = PaymentMethod::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->whereIn('id', $methodIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($methods->count() !== $methodIds->count()) {
            throw new DomainException('Todos los métodos de pago deben estar activos y pertenecer a la empresa.');
        }

        $prepared = [];

        foreach ($requested as $payment) {
            $method = $methods->get($payment['payment_method']->getKey());

            if (! $method instanceof PaymentMethod) {
                throw new DomainException('El método de pago no está disponible.');
            }

            $prepared[] = $this->paymentSnapshot($method, $payment['amount_base']);
        }

        if (bccomp($this->sumMoney(collect($prepared)->pluck('amount_base')), $total, 4) !== 0) {
            throw new DomainException('La suma de los pagos debe ser igual al total de la venta.');
        }

        return $prepared;
    }

    /**
     * @param  Collection<int, Collection<string, mixed>>  $preparedItems
     * @return array<int, array{product: Product, quantity: numeric-string}>
     */
    private function movementLines(Collection $preparedItems): array
    {
        $aggregated = [];

        foreach ($preparedItems as $item) {
            foreach ($item['components'] as $component) {
                $componentId = (int) $component['product_id'];
                $product = Product::query()->withoutGlobalScope('company')->findOrFail($componentId);
                $productId = (int) $product->getKey();
                $quantity = bcmul($component['quantity_consumed'], '-1', 6);
                $aggregated[$productId] = [
                    'product' => $product,
                    'quantity' => isset($aggregated[$productId])
                        ? bcadd($aggregated[$productId]['quantity'], $quantity, 6)
                        : $quantity,
                ];
            }
        }

        ksort($aggregated);

        return array_values($aggregated);
    }

    private function normalizeCustomerName(?string $customerName): ?string
    {
        $customerName = $customerName === null ? null : trim($customerName);

        return $customerName === '' ? null : $customerName;
    }

    /**
     * @return array{payment_method_id: int, payment_method_name: string, amount_base: numeric-string}
     */
    private function paymentSnapshot(PaymentMethod $method, int|float|string $amountBase): array
    {
        $amount = Decimal::normalize($amountBase, 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('Cada importe pagado debe ser mayor que cero.');
        }

        return [
            'payment_method_id' => (int) $method->getKey(),
            'payment_method_name' => $method->name,
            'amount_base' => $amount,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return numeric-string
     */
    private function sumMoney(Collection $values): string
    {
        return $values->reduce(fn (string $total, mixed $value): string => $this->money(
            bcadd($total, Decimal::normalize($value, 4), 8),
        ), '0.0000');
    }

    /** @return numeric-string */
    private function money(int|float|string $value): string
    {
        $value = Decimal::normalize($value, 8);
        $adjustment = bccomp($value, '0', 8) < 0 ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $adjustment, 8), '0', 4);
    }

    /** @return numeric-string */
    private function quantity(int|float|string $value): string
    {
        $value = Decimal::normalize($value, 12);
        $adjustment = bccomp($value, '0', 12) < 0 ? '-0.0000005' : '0.0000005';

        return bcadd(bcadd($value, $adjustment, 12), '0', 6);
    }
}
