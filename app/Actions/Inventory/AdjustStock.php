<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Membership;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use DomainException;

class AdjustStock
{
    public function __construct(
        private InventoryService $inventory,
        private CompanyAccess $access,
    ) {}

    public function handle(
        Membership $actor,
        Warehouse $warehouse,
        Product $product,
        int|float|string $quantityChange,
        int|float|string|null $inboundUnitCostBase = null,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        if (! $this->access->allows($actor->user, $actor->company, 'inventory.adjust')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        $quantityChange = Decimal::normalize($quantityChange, 6);
        $comparison = bccomp($quantityChange, '0', 6);

        if ($comparison === 0) {
            throw new DomainException('Stock adjustment quantity cannot be zero.');
        }

        return $this->inventory->record(
            $actor,
            $warehouse,
            $comparison > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
            [[
                'product' => $product,
                'quantity' => $quantityChange,
                'unit_cost_base' => $inboundUnitCostBase,
            ]],
            $reason,
            $occurredAt,
        );
    }
}
