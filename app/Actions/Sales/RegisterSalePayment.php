<?php

namespace App\Actions\Sales;

use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class RegisterSalePayment
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(
        Membership $actor,
        Sale $sale,
        PaymentMethod $paymentMethod,
        int|float|string $amountBase,
        ?CarbonInterface $occurredAt = null,
    ): SalePayment {
        if ($actor->company_id !== $sale->company_id
            || $paymentMethod->company_id !== $sale->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'sales.payments.create')) {
            throw new DomainException('La membership responsable no puede registrar este cobro.');
        }

        $amount = Decimal::normalize($amountBase, 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('El importe cobrado debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($actor, $sale, $paymentMethod, $amount, $occurredAt): SalePayment {
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
            $lockedMethod = PaymentMethod::query()
                ->withoutGlobalScope('company')
                ->whereKey($paymentMethod->getKey())
                ->where('company_id', $actor->company_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedActor->status !== MembershipStatus::Active
                || ! $this->access->allows($lockedActor->user, $lockedActor->company, 'sales.payments.create')) {
                throw new DomainException('La membership responsable no puede registrar este cobro.');
            }

            if ($lockedSale->status !== SaleStatus::Confirmed
                || $lockedSale->order_status === SaleOrderStatus::Cancelled) {
                throw new DomainException('No se pueden registrar cobros en una venta anulada.');
            }

            if (bccomp($amount, $lockedSale->balance_due_base, 4) === 1) {
                throw new DomainException('El cobro no puede superar el saldo pendiente.');
            }

            $paidTotal = bcadd($lockedSale->paid_total_base, $amount, 4);
            $balance = bcsub($lockedSale->total_base, $paidTotal, 4);
            $paymentStatus = bccomp($balance, '0', 4) === 0
                ? PaymentStatus::Paid
                : PaymentStatus::Partial;

            $payment = SalePayment::query()->create([
                'company_id' => $lockedSale->company_id,
                'sale_id' => $lockedSale->getKey(),
                'payment_method_id' => $lockedMethod->getKey(),
                'payment_method_name' => $lockedMethod->name,
                'amount_base' => $amount,
                'received_by_membership_id' => $lockedActor->getKey(),
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $lockedSale->update([
                'paid_total_base' => $paidTotal,
                'balance_due_base' => $balance,
                'payment_status' => $paymentStatus,
            ]);

            return $payment->load(['paymentMethod', 'receivedBy.user']);
        }, attempts: 3);
    }
}
