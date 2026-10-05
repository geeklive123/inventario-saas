<?php

namespace App\Actions\Sales;

use App\Enums\SaleOrderStatus;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleExtra;
use App\Models\Warehouse;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ConfirmSaleOnce
{
    public function __construct(private ConfirmSale $confirmSale) {}

    /**
     * @param  array<int, array{product: Product, quantity: int|float|string, component_overrides?: array<int, array{original_product_id: int, product: Product, quantity: int|float|string}>, customizations?: array<int, array{product: Product, quantity: int|float|string, unit_price_base?: int|float|string, note?: string|null}>}>  $lines
     * @param  array<int, array{payment_method: PaymentMethod, amount_base: int|float|string}>  $payments
     * @param  array<int, array{extra: SaleExtra, quantity: int|float|string, unit_price_base?: int|float|string}>  $extras
     */
    public function handle(
        string $attemptToken,
        Membership $actor,
        Branch $branch,
        Warehouse $warehouse,
        array $lines,
        array $payments,
        ?string $customerName = null,
        ?CarbonInterface $occurredAt = null,
        array $extras = [],
        SaleOrderStatus $orderStatus = SaleOrderStatus::Reserved,
        ?CarbonInterface $deliveryAt = null,
    ): Sale {
        if (! Str::isUuid($attemptToken)) {
            throw new DomainException('El intento de venta no es válido. Vuelve a abrir el formulario.');
        }

        $key = 'sales:confirm-once:'.hash('sha256', $actor->company_id.'|'.$actor->getKey().'|'.$attemptToken);
        $resultKey = $key.':sale';

        try {
            return Cache::lock($key.':lock', 30)->block(10, function () use (
                $resultKey,
                $actor,
                $branch,
                $warehouse,
                $lines,
                $payments,
                $customerName,
                $occurredAt,
                $extras,
                $orderStatus,
                $deliveryAt,
            ): Sale {
                $saleId = Cache::get($resultKey);

                if (is_numeric($saleId)) {
                    $existingSale = Sale::query()
                        ->withoutGlobalScope('company')
                        ->where('company_id', $actor->company_id)
                        ->find((int) $saleId);

                    if ($existingSale instanceof Sale) {
                        return $existingSale;
                    }
                }

                $sale = $this->confirmSale->handle(
                    $actor,
                    $branch,
                    $warehouse,
                    $lines,
                    $payments,
                    $customerName,
                    $occurredAt,
                    $extras,
                    $orderStatus,
                    $deliveryAt,
                );

                Cache::put($resultKey, $sale->getKey(), now()->addMinutes(10));

                return $sale;
            });
        } catch (LockTimeoutException) {
            throw new DomainException('La venta ya se está guardando. Espera unos segundos antes de reintentar.');
        }
    }
}
