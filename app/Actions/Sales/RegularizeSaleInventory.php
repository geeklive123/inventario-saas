<?php

namespace App\Actions\Sales;

use App\Enums\InventoryBehavior;
use App\Enums\MembershipStatus;
use App\Enums\ProductItemType;
use App\Enums\SaleInventoryPendingStatus;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleInventoryPending;
use App\Models\SaleInventoryRegularizationLine;
use App\Models\SaleItem;
use App\Models\StockBalance;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegularizeSaleInventory
{
    public function __construct(
        private InventoryService $inventory,
        private CompanyAccess $access,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: int|float|string}>  $allocations
     */
    public function handle(
        Membership $actor,
        SaleInventoryPending $pending,
        array $allocations,
    ): SaleInventoryPending {
        $this->authorize($actor, $pending);

        if ($allocations === []) {
            throw new DomainException('Agrega al menos un insumo utilizado.');
        }

        return DB::transaction(function () use ($actor, $pending, $allocations): SaleInventoryPending {
            $lockedActor = Membership::query()
                ->withoutGlobalScope('company')
                ->whereKey($actor->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();
            $sale = Sale::query()
                ->withoutGlobalScope('company')
                ->whereKey($pending->sale_id)
                ->where('company_id', $lockedActor->company_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPending = SaleInventoryPending::query()
                ->withoutGlobalScope('company')
                ->whereKey($pending->getKey())
                ->where('company_id', $lockedActor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize($lockedActor, $lockedPending);

            if ($lockedActor->status !== MembershipStatus::Active) {
                throw new DomainException('La membership responsable no está activa.');
            }

            if ($lockedPending->status !== SaleInventoryPendingStatus::Pending) {
                throw new DomainException('Este pendiente ya fue regularizado.');
            }

            if ($sale->status !== SaleStatus::Confirmed
                || $sale->inventory_status !== SaleInventoryStatus::PendingRegularization) {
                throw new DomainException('La venta ya no admite regularizaciones de inventario.');
            }

            $prepared = $this->prepareAllocations($lockedPending, $allocations);
            $movement = $this->inventory->record(
                $lockedActor,
                $lockedPending->warehouse,
                StockMovementType::SaleRegularization,
                $prepared->map(fn (array $line): array => [
                    'product' => $line['product'],
                    'quantity' => bcmul(Decimal::normalize($line['quantity'], 6), '-1', 6),
                ])->all(),
                "Regularización venta {$sale->number} · pendiente #{$lockedPending->getKey()}",
            );
            $movementLines = $movement->lines->keyBy('product_id');

            foreach ($prepared as $line) {
                $movementLine = $movementLines->get($line['product']->getKey());

                SaleInventoryRegularizationLine::query()->create([
                    'company_id' => $lockedActor->company_id,
                    'sale_inventory_pending_id' => $lockedPending->getKey(),
                    'actual_product_id' => $line['product']->getKey(),
                    'actual_product_name' => $line['product']->name,
                    'actual_product_sku' => $line['product']->sku,
                    'unit_symbol' => $line['product']->unit->symbol,
                    'quantity' => $line['quantity'],
                    'unit_cost_base' => $movementLine->unit_cost_base,
                    'total_cost_base' => $this->money(bcsub('0', $movementLine->total_cost_base, 8)),
                ]);
            }

            $lockedPending->update([
                'stock_movement_id' => $movement->getKey(),
                'regularized_quantity' => $lockedPending->required_quantity,
                'status' => SaleInventoryPendingStatus::Completed,
                'regularized_at' => now(),
                'regularized_by_membership_id' => $lockedActor->getKey(),
            ]);

            $this->completeCosts($sale, $lockedPending->sale_item_id);

            return $lockedPending->refresh()->load([
                'sale', 'saleItem', 'warehouse', 'originalComponent', 'stockMovement.lines.product',
                'regularizedBy.user', 'regularizationLines.actualProduct',
            ]);
        }, attempts: 3);
    }

    private function authorize(Membership $actor, SaleInventoryPending $pending): void
    {
        if ($actor->company_id !== $pending->company_id
            || ! $this->access->isOwnerOrAdministrator($actor->user, $actor->company)
            || ! $this->access->allows($actor->user, $actor->company, 'inventory.regularize_sales')) {
            throw new DomainException('La membership responsable no puede regularizar ventas.');
        }
    }

    /**
     * @param  array<int, array{product: Product, quantity: int|float|string}>  $allocations
     * @return Collection<int, array{product: Product, quantity: string}>
     */
    private function prepareAllocations(SaleInventoryPending $pending, array $allocations): Collection
    {
        $requested = collect($allocations);
        $productIds = $requested->map(fn (array $line): int => (int) $line['product']->getKey());

        if ($productIds->duplicates()->isNotEmpty()) {
            throw new DomainException('No repitas un mismo insumo en la regularización.');
        }

        $quantities = $requested->mapWithKeys(function (array $line): array {
            $quantity = Decimal::normalize($line['quantity'], 6);

            if (bccomp($quantity, '0', 6) <= 0) {
                throw new DomainException('Cada cantidad utilizada debe ser mayor que cero.');
            }

            return [(int) $line['product']->getKey() => $quantity];
        });
        $total = $quantities->reduce(
            fn (string $sum, string $quantity): string => bcadd($sum, $quantity, 6),
            '0.000000',
        );
        $remaining = bcsub($pending->required_quantity, $pending->regularized_quantity, 6);

        if (bccomp($total, $remaining, 6) !== 0) {
            throw new DomainException("La cantidad asignada debe ser exactamente {$remaining}.");
        }

        $products = Product::query()
            ->withoutGlobalScope('company')
            ->with('unit:id,symbol')
            ->where('company_id', $pending->company_id)
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw new DomainException('Todos los insumos utilizados deben estar activos y pertenecer a la empresa.');
        }

        $balances = StockBalance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $pending->company_id)
            ->where('warehouse_id', $pending->warehouse_id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        return $productIds->map(function (int $productId) use ($balances, $products, $quantities): array {
            $product = $products->get($productId);
            $quantity = $quantities->get($productId);

            if (! $product instanceof Product || ! is_string($quantity)) {
                throw new DomainException('No se pudo preparar uno de los insumos utilizados.');
            }

            $quantity = Decimal::normalize($quantity, 6);

            $balance = $balances->get($productId);
            $available = $balance instanceof StockBalance ? $balance->quantity : '0.000000';

            if (bccomp($available, $quantity, 6) < 0) {
                throw new DomainException("Stock insuficiente para {$product->name}. Disponible: {$available}. Necesario: {$quantity}.");
            }

            return ['product' => $product, 'quantity' => $quantity];
        });
    }

    private function completeCosts(Sale $sale, int $saleItemId): void
    {
        $itemHasPendings = SaleInventoryPending::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sale_item_id', $saleItemId)
            ->where('status', SaleInventoryPendingStatus::Pending)
            ->exists();

        if (! $itemHasPendings) {
            $item = SaleItem::query()
                ->withoutGlobalScope('company')
                ->whereKey($saleItemId)
                ->where('company_id', $sale->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($item->total_cost_base === null) {
                $automaticCost = $item->components()->whereNotNull('total_cost_base')->sum('total_cost_base');
                $regularizedCost = SaleInventoryRegularizationLine::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $sale->company_id)
                    ->whereHas('pending', fn ($query) => $query->where('sale_item_id', $item->getKey()))
                    ->sum('total_cost_base');
                $totalCost = $this->money(bcadd((string) $automaticCost, (string) $regularizedCost, 8));

                $item->update([
                    'unit_cost_base' => $this->money(bcdiv($totalCost, $item->quantity, 8)),
                    'total_cost_base' => $totalCost,
                    'gross_margin_base' => $this->money(bcsub(
                        $item->subtotal_base,
                        Decimal::normalize($totalCost, 4),
                        8,
                    )),
                ]);
            }
        }

        $saleHasPendings = SaleInventoryPending::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->where('sale_id', $sale->getKey())
            ->where('status', SaleInventoryPendingStatus::Pending)
            ->exists();

        if (! $saleHasPendings) {
            $totalCost = $this->money((string) $sale->items()->sum('total_cost_base'));
            $sale->update([
                'inventory_status' => SaleInventoryStatus::Complete,
                'total_cost_base' => $totalCost,
                'gross_margin_base' => $this->money(bcsub(
                    $sale->total_base,
                    Decimal::normalize($totalCost, 4),
                    8,
                )),
            ]);
        }
    }

    private function money(int|float|string $value): string
    {
        $value = Decimal::normalize($value, 8);
        $adjustment = bccomp($value, '0', 8) < 0 ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $adjustment, 8), '0', 4);
    }
}
