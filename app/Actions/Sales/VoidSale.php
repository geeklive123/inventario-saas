<?php

namespace App\Actions\Sales;

use App\Enums\ModuleCode;
use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleStockMovementKind;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\SaleStockMovement;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class VoidSale
{
    public function __construct(
        private InventoryService $inventory,
        private CompanyAccess $access,
    ) {}

    public function handle(
        Membership $actor,
        Sale $sale,
        string $reason,
        ?CarbonInterface $occurredAt = null,
    ): Sale {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Indica el motivo de la anulación.');
        }

        if ($actor->company_id !== $sale->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'sales.void')
            || ! $this->access->moduleEnabled($actor->company, ModuleCode::Inventory)) {
            throw new DomainException('La membership responsable no puede anular esta venta.');
        }

        return DB::transaction(function () use ($actor, $sale, $reason, $occurredAt): Sale {
            $lockedSale = Sale::query()
                ->withoutGlobalScope('company')
                ->whereKey($sale->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSale->status !== SaleStatus::Confirmed) {
                throw new DomainException('La venta ya está anulada.');
            }

            if (bccomp($lockedSale->paid_total_base, '0', 4) === 1) {
                throw new DomainException('No se puede anular una venta cobrada sin registrar antes una devolución.');
            }

            $link = SaleStockMovement::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $actor->company_id)
                ->where('sale_id', $lockedSale->getKey())
                ->where('kind', SaleStockMovementKind::Consumption)
                ->lockForUpdate()
                ->firstOrFail();
            $movement = StockMovement::query()
                ->withoutGlobalScope('company')
                ->whereKey($link->stock_movement_id)
                ->where('company_id', $actor->company_id)
                ->firstOrFail();
            $reversal = $this->inventory->reverse(
                $actor,
                $movement,
                "Anulación {$lockedSale->number}: {$reason}",
                $occurredAt,
            );

            SaleStockMovement::query()->create([
                'company_id' => $actor->company_id,
                'sale_id' => $lockedSale->getKey(),
                'stock_movement_id' => $reversal->getKey(),
                'kind' => SaleStockMovementKind::Reversal,
            ]);
            $lockedSale->update([
                'status' => SaleStatus::Voided,
                'order_status' => SaleOrderStatus::Cancelled,
                'voided_by_membership_id' => $actor->getKey(),
                'voided_at' => $occurredAt ?? now(),
                'void_reason' => $reason,
            ]);

            return $lockedSale->load([
                'items.components', 'extraLines', 'payments.receivedBy.user',
                'stockMovementLinks.stockMovement.lines.product',
                'confirmedBy.user', 'voidedBy.user',
            ]);
        }, attempts: 3);
    }
}
