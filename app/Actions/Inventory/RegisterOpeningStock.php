<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Membership;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use Carbon\CarbonInterface;
use DomainException;

class RegisterOpeningStock
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
        $this->authorize($actor);

        $alreadyOpened = StockMovementLine::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $actor->company_id)
            ->where('product_id', $product->getKey())
            ->whereHas('movement', fn ($query) => $query
                ->withoutGlobalScope('company')
                ->where('company_id', $actor->company_id)
                ->where('warehouse_id', $warehouse->getKey())
                ->where('type', StockMovementType::Opening))
            ->exists();

        if ($alreadyOpened) {
            throw new DomainException('Este insumo ya tiene una existencia inicial registrada en el almacén.');
        }

        return $this->inventory->record($actor, $warehouse, StockMovementType::Opening, [[
            'product' => $product,
            'quantity' => $quantity,
            'unit_cost_base' => $unitCostBase,
        ]], $reason, $occurredAt);
    }

    private function authorize(Membership $actor): void
    {
        if (! $this->access->allows($actor->user, $actor->company, 'inventory.opening')) {
            throw new DomainException('The membership actor is not authorized.');
        }
    }
}
