<?php

namespace App\Services\Sales;

use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Sale;
use App\Support\CustomerPhoneNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class PotentialDuplicateSaleFinder
{
    public function __construct(private CustomerPhoneNormalizer $phoneNormalizer) {}

    /**
     * @param  array<int, int>  $productIds
     * @return array{sale_id: int, number: string, relevant_at: CarbonImmutable, customer: string, products: list<string>, status: string}|null
     */
    public function find(
        Company $company,
        ?string $customer,
        CarbonInterface $occurredAt,
        ?CarbonInterface $deliveryAt,
        array $productIds,
    ): ?array {
        $normalizedPhone = $this->phoneNormalizer->normalize($customer);
        $productIds = collect($productIds)->map(fn (int|string $id): int => (int) $id)->unique()->values();

        if ($normalizedPhone === null || $productIds->isEmpty()) {
            return null;
        }

        $timezone = $company->timezone;
        $relevantAt = CarbonImmutable::instance($deliveryAt ?? $occurredAt)->setTimezone($timezone);
        $dayStartsAt = $relevantAt->startOfDay()->utc();
        $dayEndsAt = $relevantAt->endOfDay()->utc();

        $sale = Sale::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('status', SaleStatus::Confirmed)
            ->where('order_status', '!=', SaleOrderStatus::Cancelled)
            ->where(function (Builder $query) use ($dayStartsAt, $dayEndsAt): void {
                $query->whereBetween('delivery_at', [$dayStartsAt, $dayEndsAt])
                    ->orWhere(function (Builder $query) use ($dayStartsAt, $dayEndsAt): void {
                        $query->whereNull('delivery_at')->whereBetween('occurred_at', [$dayStartsAt, $dayEndsAt]);
                    });
            })
            ->whereHas('items', function (Builder $query) use ($company, $productIds): void {
                $query->withoutGlobalScope('company')
                    ->where('company_id', $company->getKey())
                    ->whereIn('product_id', $productIds);
            })
            ->with(['items' => function ($query) use ($company, $productIds): void {
                $query->withoutGlobalScope('company')
                    ->where('company_id', $company->getKey())
                    ->whereIn('product_id', $productIds)
                    ->orderBy('id');
            }])
            ->orderByDesc('occurred_at')
            ->get()
            ->first(fn (Sale $candidate): bool => $this->phoneNormalizer->normalize($candidate->customer_name) === $normalizedPhone);

        if (! $sale instanceof Sale) {
            return null;
        }

        return [
            'sale_id' => (int) $sale->getKey(),
            'number' => $sale->number,
            'relevant_at' => CarbonImmutable::instance($sale->delivery_at ?? $sale->occurred_at)->setTimezone($timezone),
            'customer' => $sale->customer_name ?: $normalizedPhone,
            'products' => array_values($sale->items->map(fn ($item): string => (string) $item->product_name)->unique()->all()),
            'status' => $sale->order_status->label(),
        ];
    }
}
