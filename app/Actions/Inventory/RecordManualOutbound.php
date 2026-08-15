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

class RecordManualOutbound
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
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        if (! $this->access->allows($actor->user, $actor->company, 'inventory.adjust')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        $quantity = Decimal::normalize($quantity, 6);

        if (bccomp($quantity, '0', 6) <= 0) {
            throw new DomainException('Manual outbound quantity must be positive.');
        }

        return $this->inventory->record($actor, $warehouse, StockMovementType::AdjustmentOut, [[
            'product' => $product,
            'quantity' => bcmul($quantity, '-1', 6),
        ]], $reason, $occurredAt);
    }
}
