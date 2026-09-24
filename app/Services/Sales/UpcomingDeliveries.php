<?php

namespace App\Services\Sales;

use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Membership;
use App\Models\Sale;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class UpcomingDeliveries
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * @return list<array{
     *     key: string,
     *     delivery_at: CarbonImmutable,
     *     overdue: bool,
     *     orders_count: int,
     *     bouquets_quantity: numeric-string,
     *     orders: list<array{sale_id: int, number: string, customer: string, status: string, bouquets_quantity: numeric-string}>
     * }>
     */
    public function forMembership(Membership $membership, ?CarbonInterface $now = null): array
    {
        $membership->loadMissing(['user', 'company']);

        if (! $this->access->allows($membership->user, $membership->company, 'sales.view', mutation: false)) {
            return [];
        }

        $currentTime = $now === null
            ? CarbonImmutable::now($membership->company->timezone)
            : CarbonImmutable::instance($now)->setTimezone($membership->company->timezone);
        $upperLimit = $currentTime->addHour()->utc();

        $sales = Sale::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $membership->company_id)
            ->where('status', SaleStatus::Confirmed)
            ->whereIn('order_status', [
                SaleOrderStatus::Reserved,
                SaleOrderStatus::Preparing,
                SaleOrderStatus::Ready,
            ])
            ->whereNotNull('delivery_at')
            ->where('delivery_at', '<=', $upperLimit)
            ->withSum('items as bouquets_quantity', 'quantity')
            ->orderBy('delivery_at')
            ->orderBy('id')
            ->get([
                'id', 'company_id', 'number', 'customer_name', 'order_status', 'delivery_at',
            ]);

        return $this->groupDeliveries($sales, $currentTime);
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @return list<array{
     *     key: string,
     *     delivery_at: CarbonImmutable,
     *     overdue: bool,
     *     orders_count: int,
     *     bouquets_quantity: numeric-string,
     *     orders: list<array{sale_id: int, number: string, customer: string, status: string, bouquets_quantity: numeric-string}>
     * }>
     */
    private function groupDeliveries(Collection $sales, CarbonImmutable $currentTime): array
    {
        return array_values($sales
            ->groupBy(fn (Sale $sale): string => $sale->delivery_at
                ->setTimezone($currentTime->timezone)
                ->format('Y-m-d H:i'))
            ->map(function (Collection $group, string $key) use ($currentTime): array {
                $deliveryAt = CarbonImmutable::instance($group->firstOrFail()->delivery_at)
                    ->setTimezone($currentTime->timezone);
                $bouquetsQuantity = $group->reduce(
                    fn (string $total, Sale $sale): string => bcadd(
                        $total,
                        Decimal::normalize($sale->getAttribute('bouquets_quantity') ?? 0, 6),
                        6,
                    ),
                    '0.000000',
                );

                return [
                    'key' => $key,
                    'delivery_at' => $deliveryAt,
                    'overdue' => $deliveryAt->lessThan($currentTime),
                    'orders_count' => $group->count(),
                    'bouquets_quantity' => $bouquetsQuantity,
                    'orders' => array_values($group->map(fn (Sale $sale): array => [
                        'sale_id' => (int) $sale->getKey(),
                        'number' => $sale->number,
                        'customer' => $sale->customer_name ?: 'Consumidor final',
                        'status' => $sale->order_status->label(),
                        'bouquets_quantity' => Decimal::normalize(
                            $sale->getAttribute('bouquets_quantity') ?? 0,
                            6,
                        ),
                    ])->all()),
                ];
            })
            ->all());
    }
}
