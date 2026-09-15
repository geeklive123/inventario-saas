<?php

namespace App\Services\Inventory;

use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Enums\StockMovementType;
use App\Models\Membership;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\Warehouse;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * @return array{quantity_before: numeric-string, quantity_after: numeric-string, purchase_total_base: numeric-string, inventory_value_before_base: numeric-string, inventory_value_after_base: numeric-string, average_unit_cost_before_base: numeric-string, average_unit_cost_after_base: numeric-string}
     */
    public function previewInbound(
        Product $product,
        ?StockBalance $balance,
        int|float|string $quantity,
        int|float|string $unitCostBase,
    ): array {
        if ($balance !== null
            && ($balance->company_id !== $product->company_id || $balance->product_id !== $product->getKey())) {
            throw new DomainException('The stock balance must belong to the product company and product.');
        }

        $quantity = Decimal::normalize($quantity, 6);

        if (bccomp($quantity, '0', 6) <= 0) {
            throw new DomainException('Inbound stock quantity must be positive.');
        }

        $workingBalance = $balance ?? new StockBalance([
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
            'quantity' => 0,
            'inventory_value_base' => 0,
            'average_unit_cost_base' => 0,
        ]);
        $result = $this->calculateInbound($workingBalance, $quantity, $unitCostBase);

        return [
            'quantity_before' => $workingBalance->quantity,
            'quantity_after' => bcadd($workingBalance->quantity, $quantity, 6),
            'purchase_total_base' => $this->money(bcmul($quantity, $result['unit_cost'], 8)),
            'inventory_value_before_base' => $workingBalance->inventory_value_base,
            'inventory_value_after_base' => $result['inventory_value_after'],
            'average_unit_cost_before_base' => $workingBalance->average_unit_cost_base,
            'average_unit_cost_after_base' => $result['average_unit_cost_after'],
        ];
    }

    /**
     * @param  array<int, array{product: Product, quantity: int|float|string, unit_cost_base?: int|float|string|null}>  $lines
     */
    public function record(
        Membership $actor,
        Warehouse $warehouse,
        StockMovementType $type,
        array $lines,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
        ?StockMovement $reversalOf = null,
    ): StockMovement {
        if ($lines === []) {
            throw new DomainException('A stock movement requires at least one line.');
        }

        $this->validateCompanyContext($actor, $warehouse, $reversalOf);

        return DB::transaction(function () use (
            $actor, $warehouse, $type, $lines, $reason, $occurredAt, $reversalOf,
        ): StockMovement {
            if ($reversalOf !== null) {
                $this->lockReversibleMovement($reversalOf);
            }

            $movement = StockMovement::query()->create([
                'company_id' => $actor->company_id,
                'warehouse_id' => $warehouse->getKey(),
                'type' => $type,
                'reversal_of_movement_id' => $reversalOf?->getKey(),
                'created_by_membership_id' => $actor->getKey(),
                'reason' => $reason,
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $productIds = [];

            foreach (collect($lines)->sortBy(fn (array $line): int => (int) $line['product']->getKey()) as $line) {
                $productId = (int) $line['product']->getKey();

                if (isset($productIds[$productId])) {
                    throw new DomainException('A stock movement cannot repeat a product.');
                }

                $productIds[$productId] = true;
                $this->applyLine($movement, $warehouse, $type, $line);
            }

            return $movement->load(['warehouse', 'lines.product']);
        }, attempts: 3);
    }

    public function reverse(
        Membership $actor,
        StockMovement $original,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        $original->loadMissing(['warehouse', 'lines.product']);

        $lines = $original->lines->map(fn (StockMovementLine $line): array => [
            'product' => $line->product,
            'quantity' => bcmul($line->quantity, '-1', 6),
            'unit_cost_base' => bccomp($line->quantity, '0', 6) < 0
                ? $line->unit_cost_base
                : null,
        ])->all();

        return $this->record(
            $actor,
            $original->warehouse,
            StockMovementType::Reversal,
            $lines,
            $reason,
            $occurredAt,
            $original,
        );
    }

    /**
     * @param  array{product: Product, quantity: int|float|string, unit_cost_base?: int|float|string|null}  $line
     */
    private function applyLine(
        StockMovement $movement,
        Warehouse $warehouse,
        StockMovementType $type,
        array $line,
    ): void {
        $product = $line['product'];
        $quantity = Decimal::normalize($line['quantity'], 6);

        $this->validateStockProduct($movement, $product, $quantity);
        $this->validateMovementDirection($type, $quantity);

        $balance = StockBalance::query()->firstOrCreate(
            [
                'company_id' => $movement->company_id,
                'warehouse_id' => $warehouse->getKey(),
                'product_id' => $product->getKey(),
            ],
            [
                'quantity' => 0,
                'inventory_value_base' => 0,
                'average_unit_cost_base' => 0,
            ],
        );
        $wasCreated = $balance->wasRecentlyCreated;
        $balance = StockBalance::query()
            ->withoutGlobalScope('company')
            ->whereKey($balance->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($type === StockMovementType::Opening && ! $wasCreated) {
            throw new DomainException('Opening stock can only be registered before other stock activity.');
        }

        $beforeQuantity = $balance->quantity;
        $beforeValue = $balance->inventory_value_base;
        $beforeAverage = $balance->average_unit_cost_base;
        $afterQuantity = bcadd($beforeQuantity, $quantity, 6);

        if (bccomp($afterQuantity, '0', 6) < 0 && ! $movement->company->allow_negative_stock) {
            throw new DomainException('Negative stock is disabled for this company.');
        }

        if (bccomp($quantity, '0', 6) > 0) {
            $result = $this->calculateInbound(
                $balance,
                $quantity,
                $line['unit_cost_base'] ?? null,
            );
        } else {
            $result = $this->calculateOutbound($balance, $product, $quantity);
        }

        $balance->update([
            'quantity' => $afterQuantity,
            'inventory_value_base' => $result['inventory_value_after'],
            'average_unit_cost_base' => $result['average_unit_cost_after'],
            'last_inbound_unit_cost_base' => $result['last_inbound_unit_cost'],
        ]);

        StockMovementLine::query()->create([
            'company_id' => $movement->company_id,
            'stock_movement_id' => $movement->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'quantity_before' => $beforeQuantity,
            'quantity_after' => $afterQuantity,
            'unit_cost_base' => $result['unit_cost'],
            'total_cost_base' => $this->money(bcmul($quantity, $result['unit_cost'], 8)),
            'inventory_value_before_base' => $beforeValue,
            'inventory_value_after_base' => $result['inventory_value_after'],
            'average_unit_cost_before_base' => $beforeAverage,
            'average_unit_cost_after_base' => $result['average_unit_cost_after'],
            'cost_variance_base' => $result['cost_variance'],
        ]);
    }

    /**
     * @param  numeric-string  $quantity
     * @return array{unit_cost: numeric-string, inventory_value_after: numeric-string, average_unit_cost_after: numeric-string, last_inbound_unit_cost: numeric-string, cost_variance: numeric-string}
     */
    private function calculateInbound(
        StockBalance $balance,
        string $quantity,
        int|float|string|null $providedUnitCost,
    ): array {
        if ($providedUnitCost === null) {
            throw new DomainException('Inbound stock requires a non-negative unit cost.');
        }

        $unitCost = $this->money(Decimal::normalize($providedUnitCost, 8));

        if (bccomp($unitCost, '0', 4) < 0) {
            throw new DomainException('Inbound stock requires a non-negative unit cost.');
        }
        $afterQuantity = bcadd($balance->quantity, $quantity, 6);
        $costVariance = '0.0000';

        if (bccomp($balance->quantity, '0', 6) < 0) {
            $coveredNegativeQuantity = bccomp($quantity, bcsub('0', $balance->quantity, 6), 6) <= 0
                ? $quantity
                : bcsub('0', $balance->quantity, 6);
            $costVariance = $this->money(bcmul(
                bcsub($unitCost, $balance->average_unit_cost_base, 8),
                $coveredNegativeQuantity,
                8,
            ));

            if (bccomp($afterQuantity, '0', 6) <= 0) {
                $average = $balance->last_inbound_unit_cost_base === null
                    ? $unitCost
                    : $balance->average_unit_cost_base;
                $value = bccomp($afterQuantity, '0', 6) === 0
                    ? '0.0000'
                    : $this->money(bcmul($afterQuantity, $average, 8));
            } else {
                $average = $unitCost;
                $value = $this->money(bcmul($afterQuantity, $unitCost, 8));
            }
        } else {
            $value = $this->money(bcadd(
                $balance->inventory_value_base,
                bcmul($quantity, $unitCost, 8),
                8,
            ));
            $average = $this->money(bcdiv($value, $afterQuantity, 8));
        }

        return [
            'unit_cost' => $unitCost,
            'inventory_value_after' => $value,
            'average_unit_cost_after' => $average,
            'last_inbound_unit_cost' => $unitCost,
            'cost_variance' => $costVariance,
        ];
    }

    /**
     * @param  numeric-string  $quantity
     * @return array{unit_cost: numeric-string, inventory_value_after: numeric-string, average_unit_cost_after: numeric-string, last_inbound_unit_cost: numeric-string|null, cost_variance: numeric-string}
     */
    private function calculateOutbound(StockBalance $balance, Product $product, string $quantity): array
    {
        $hasKnownAverage = bccomp($balance->quantity, '0', 6) !== 0
            || $balance->last_inbound_unit_cost_base !== null;
        $unitCost = $this->currentUnitCost($product, $balance);

        if ($unitCost === null) {
            throw new DomainException('An outbound movement requires a known average or fallback unit cost.');
        }

        $unitCost = $this->money($unitCost);
        $afterQuantity = bcadd($balance->quantity, $quantity, 6);
        $afterValue = bccomp($afterQuantity, '0', 6) === 0
            ? '0.0000'
            : $this->money(bcadd(
                $balance->inventory_value_base,
                bcmul($quantity, $unitCost, 8),
                8,
            ));

        return [
            'unit_cost' => $unitCost,
            'inventory_value_after' => $afterValue,
            'average_unit_cost_after' => $hasKnownAverage ? $balance->average_unit_cost_base : $unitCost,
            'last_inbound_unit_cost' => $balance->last_inbound_unit_cost_base,
            'cost_variance' => '0.0000',
        ];
    }

    /** @return numeric-string|null */
    public function currentUnitCost(Product $product, ?StockBalance $balance): ?string
    {
        if ($balance !== null
            && ($balance->company_id !== $product->company_id || $balance->product_id !== $product->getKey())) {
            throw new DomainException('The stock balance must belong to the product company and product.');
        }

        if ($balance !== null
            && (bccomp($balance->quantity, '0', 6) !== 0 || $balance->last_inbound_unit_cost_base !== null)) {
            return $balance->average_unit_cost_base;
        }

        return $product->fallback_unit_cost_base;
    }

    private function validateCompanyContext(
        Membership $actor,
        Warehouse $warehouse,
        ?StockMovement $reversalOf,
    ): void {
        if ($actor->company_id !== $warehouse->company_id
            || ($reversalOf !== null && (
                $reversalOf->company_id !== $actor->company_id
                || $reversalOf->warehouse_id !== $warehouse->getKey()
            ))) {
            throw new DomainException('Inventory records must belong to the same company and warehouse.');
        }
    }

    /** @param numeric-string $quantity */
    private function validateStockProduct(StockMovement $movement, Product $product, string $quantity): void
    {
        if ($movement->company_id !== $product->company_id) {
            throw new DomainException('The product must belong to the movement company.');
        }

        if ($product->item_type !== ProductItemType::Physical
            || $product->inventory_behavior !== InventoryBehavior::Self) {
            throw new DomainException('Only self-managed physical products can have a stock balance.');
        }

        if (bccomp($quantity, '0', 6) === 0) {
            throw new DomainException('Stock movement quantity cannot be zero.');
        }
    }

    /** @param numeric-string $quantity */
    private function validateMovementDirection(StockMovementType $type, string $quantity): void
    {
        if (($type === StockMovementType::Opening || $type === StockMovementType::AdjustmentIn)
            && bccomp($quantity, '0', 6) <= 0) {
            throw new DomainException('This movement type requires a positive quantity.');
        }

        if (in_array($type, [
            StockMovementType::AdjustmentOut,
            StockMovementType::Waste,
            StockMovementType::Sale,
            StockMovementType::SaleRegularization,
        ], true)
            && bccomp($quantity, '0', 6) >= 0) {
            throw new DomainException('This movement type requires a negative quantity.');
        }
    }

    private function lockReversibleMovement(StockMovement $movement): void
    {
        StockMovement::query()
            ->withoutGlobalScope('company')
            ->whereKey($movement->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (StockMovement::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $movement->company_id)
            ->where('reversal_of_movement_id', $movement->getKey())
            ->exists()) {
            throw new DomainException('The stock movement has already been reversed.');
        }
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function money(string $value): string
    {
        $adjustment = bccomp($value, '0', 8) < 0 ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $adjustment, 8), '0', 4);
    }
}
