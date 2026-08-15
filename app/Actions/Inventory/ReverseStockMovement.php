<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Membership;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use Carbon\CarbonInterface;
use DomainException;

class ReverseStockMovement
{
    public function __construct(
        private InventoryService $inventory,
        private CompanyAccess $access,
    ) {}

    public function handle(
        Membership $actor,
        StockMovement $movement,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        if (! $this->access->allows($actor->user, $actor->company, 'inventory.reverse')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        if ($actor->company_id !== $movement->company_id) {
            throw new DomainException('The movement must belong to the actor company.');
        }

        if ($movement->type === StockMovementType::Sale) {
            throw new DomainException('Las salidas por venta solo pueden revertirse anulando la venta.');
        }

        return $this->inventory->reverse($actor, $movement, $reason, $occurredAt);
    }
}
