<?php

namespace App\Actions\Sales;

use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Membership;
use App\Models\Sale;
use App\Support\Authorization\CompanyAccess;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateSaleOrderStatus
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, Sale $sale, SaleOrderStatus $status, ?CarbonInterface $deliveryAt = null): Sale
    {
        if ($actor->company_id !== $sale->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'sales.create')) {
            throw new DomainException('La membership responsable no puede cambiar el estado del pedido.');
        }

        if ($status === SaleOrderStatus::Cancelled) {
            throw new DomainException('Para cancelar un pedido debes anular la venta.');
        }

        return DB::transaction(function () use ($actor, $sale, $status, $deliveryAt): Sale {
            $lockedActor = Membership::query()
                ->withoutGlobalScope('company')
                ->whereKey($actor->getKey())
                ->where('company_id', $sale->company_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSale = Sale::query()
                ->withoutGlobalScope('company')
                ->whereKey($sale->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->allows($lockedActor->user, $lockedActor->company, 'sales.create')) {
                throw new DomainException('La membership responsable no puede cambiar el estado del pedido.');
            }

            if ($lockedSale->status !== SaleStatus::Confirmed) {
                throw new DomainException('No se puede cambiar el estado de una venta anulada.');
            }

            if ($status === SaleOrderStatus::Reserved && $deliveryAt === null && $lockedSale->delivery_at === null) {
                throw new DomainException('La fecha y hora de entrega es obligatoria para una reserva.');
            }

            $changes = ['order_status' => $status];

            if ($deliveryAt !== null) {
                $changes['delivery_at'] = $deliveryAt;
                $changes['delivery_updated_by_membership_id'] = $lockedActor->getKey();
            }

            $lockedSale->update($changes);

            return $lockedSale;
        }, attempts: 3);
    }
}
