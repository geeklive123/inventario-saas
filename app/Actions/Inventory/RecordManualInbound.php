<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Membership;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

class RecordManualInbound
{
    public function __construct(
        private InventoryService $inventory,
        private CompanyAccess $access,
    ) {}

    public function handle(
        Membership $actor,
        Warehouse $warehouse,
        Product $product,
        int|float|string $quantity,
        int|float|string $unitCostBase,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        if (! $this->access->allows($actor->user, $actor->company, 'inventory.adjust')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        $effectiveOccurredAt = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($occurredAt)->utc();

        if ($effectiveOccurredAt->isFuture()) {
            throw new DomainException('La fecha efectiva del ingreso no puede ser futura.');
        }

        return $this->inventory->record($actor, $warehouse, StockMovementType::AdjustmentIn, [[
            'product' => $product,
            'quantity' => $quantity,
            'unit_cost_base' => $unitCostBase,
        ]], $reason, $effectiveOccurredAt);
    }
}
