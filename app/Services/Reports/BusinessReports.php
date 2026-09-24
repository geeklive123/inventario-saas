<?php

namespace App\Services\Reports;

use App\Enums\ExpenseReceiptType;
use App\Enums\ExpenseStatus;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleExtraLine;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Support\Authorization\ReportAccess;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BusinessReports
{
    public function __construct(private ReportAccess $access) {}

    /** @return array<string, mixed> */
    public function summary(
        Membership $membership,
        string $period = 'today',
        ?string $customFrom = null,
        ?string $customTo = null,
    ): array {
        $membership->loadMissing(['user', 'company.baseCurrency']);
        [$from, $to] = $this->range($membership, $period, $customFrom, $customTo);
        $capabilities = $this->access->capabilities($membership);
        $companyId = $membership->company_id;

        $confirmedSales = Sale::query()->where('company_id', $companyId)
            ->where('status', SaleStatus::Confirmed)->whereBetween('occurred_at', [$from, $to]);
        $salesCount = (clone $confirmedSales)->count();
        $totalSold = Decimal::normalize((clone $confirmedSales)->sum('total_base'), 4);
        $hasIncompleteCosts = (clone $confirmedSales)
            ->where('inventory_status', SaleInventoryStatus::PendingRegularization)
            ->exists();
        $historicalCost = $hasIncompleteCosts
            ? null
            : Decimal::normalize((clone $confirmedSales)->sum('total_cost_base'), 4);
        $pending = Decimal::normalize((clone $confirmedSales)->sum('balance_due_base'), 4);
        $voidedCount = Sale::query()->where('company_id', $companyId)
            ->where('status', SaleStatus::Voided)->whereBetween('occurred_at', [$from, $to])->count();

        $paymentsTable = (new SalePayment)->getTable();
        $payments = SalePayment::query()->where("{$paymentsTable}.company_id", $companyId)
            ->whereBetween("{$paymentsTable}.occurred_at", [$from, $to])
            ->whereHas('sale', fn (Builder $query) => $query->where('status', SaleStatus::Confirmed));
        $collected = Decimal::normalize((clone $payments)->sum('amount_base'), 4);
        $paymentsByMethod = $capabilities['financial'] ? $this->paymentsByMethod($payments) : [];
        $cashCollected = $this->paymentAmountByCode($paymentsByMethod, 'cash');
        $qrCollected = $this->paymentAmountByCode($paymentsByMethod, 'qr');
        $otherCollected = Decimal::normalize(collect($paymentsByMethod)
            ->reject(fn (array $method): bool => in_array($method['code'], ['cash', 'qr'], true))
            ->sum('amount_base'), 4);

        $confirmedExpenses = Expense::query()->where('company_id', $companyId)
            ->where('status', ExpenseStatus::Confirmed)->whereBetween('occurred_at', [$from, $to]);
        $expenseTotal = Decimal::normalize((clone $confirmedExpenses)->sum('amount_base'), 4);
        $grossMargin = $historicalCost === null ? null : Decimal::normalize(bcsub($totalSold, $historicalCost, 4), 4);
        $estimatedResult = $grossMargin === null ? null : Decimal::normalize(bcsub($grossMargin, $expenseTotal, 4), 4);

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'capabilities' => $capabilities,
            'general' => [
                'sales_count' => $capabilities['sales'] || $capabilities['financial'] ? $salesCount : null,
                'total_sold_base' => $capabilities['financial'] ? $totalSold : null,
                'collected_base' => $capabilities['financial'] ? $collected : null,
                'pending_base' => $capabilities['financial'] ? $pending : null,
                'expenses_base' => $capabilities['expenses'] || $capabilities['financial'] ? $expenseTotal : null,
                'historical_cost_base' => $capabilities['financial'] ? $historicalCost : null,
                'estimated_result_base' => $capabilities['financial'] ? $estimatedResult : null,
            ],
            'sales' => $capabilities['sales'] ? [
                'count' => $salesCount,
                'voided_count' => $voidedCount,
                'total_sold_base' => $capabilities['financial'] ? $totalSold : null,
                'collected_base' => $capabilities['financial'] ? $collected : null,
                'pending_base' => $capabilities['financial'] ? $pending : null,
                'average_ticket_base' => $capabilities['financial'] && $salesCount > 0
                    ? Decimal::normalize(bcdiv($totalSold, (string) $salesCount, 4), 4) : null,
                'cash_collected_base' => $capabilities['financial'] ? $cashCollected : null,
                'qr_collected_base' => $capabilities['financial'] ? $qrCollected : null,
                'other_collected_base' => $capabilities['financial'] ? $otherCollected : null,
                'by_payment_method' => $paymentsByMethod,
                'by_responsible' => $this->salesByResponsible($confirmedSales),
            ] : null,
            'orders' => $capabilities['sales']
                ? $this->orderReport($companyId, $from, $to, $capabilities['financial']) : null,
            'extras' => $capabilities['sales']
                ? $this->extraReport($companyId, $from, $to, $capabilities['financial']) : null,
            'bouquets' => $capabilities['sales']
                ? $this->bouquetReport($companyId, $from, $to, $capabilities['financial']) : null,
            'inventory' => $capabilities['inventory']
                ? $this->inventoryReport($companyId, $from, $to, $capabilities['financial']) : null,
            'expenses' => $capabilities['expenses'] ? $this->expenseReport($confirmedExpenses, $expenseTotal) : null,
            'profit' => $capabilities['financial'] ? [
                'total_sold_base' => $totalSold,
                'historical_cost_base' => $historicalCost,
                'gross_margin_base' => $grossMargin,
                'expenses_base' => $expenseTotal,
                'estimated_result_base' => $estimatedResult,
            ] : null,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Membership $membership, string $period, ?string $customFrom, ?string $customTo): array
    {
        $timezone = $membership->company->timezone;
        $now = CarbonImmutable::now($timezone);
        $localFrom = match ($period) {
            'today' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            'custom' => $this->customDate($customFrom, $timezone)->startOfDay(),
            default => throw new DomainException('El período seleccionado no es válido.'),
        };
        $localTo = $period === 'custom'
            ? $this->customDate($customTo, $timezone)->endOfDay() : $now->endOfDay();

        if ($localFrom->greaterThan($localTo)) {
            throw new DomainException('La fecha inicial no puede ser posterior a la fecha final.');
        }

        return [$localFrom->utc(), $localTo->utc()];
    }

    private function customDate(?string $date, string $timezone): CarbonImmutable
    {
        if ($date === null || $date === '') {
            throw new DomainException('Completa ambas fechas del rango personalizado.');
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    }

    /**
     * @param  Builder<SalePayment>  $payments
     * @return list<array{code: string, name: string, amount_base: numeric-string}>
     */
    private function paymentsByMethod(Builder $payments): array
    {
        $paymentsTable = (new SalePayment)->getTable();
        $methodsTable = (new PaymentMethod)->getTable();

        return array_values((clone $payments)
            ->join($methodsTable, function ($join) use ($methodsTable, $paymentsTable): void {
                $join->on("{$methodsTable}.id", '=', "{$paymentsTable}.payment_method_id")
                    ->on("{$methodsTable}.company_id", '=', "{$paymentsTable}.company_id");
            })
            ->select("{$paymentsTable}.payment_method_name")
            ->addSelect("{$methodsTable}.code")
            ->selectRaw('SUM(sale_payments.amount_base) as amount_base')
            ->groupBy("{$methodsTable}.code", "{$paymentsTable}.payment_method_name")
            ->orderByDesc('amount_base')->get()
            ->map(fn (SalePayment $payment): array => [
                'code' => (string) $payment->getAttribute('code'),
                'name' => $payment->payment_method_name,
                'amount_base' => Decimal::normalize($payment->amount_base, 4),
            ])->all());
    }

    /** @param list<array{code: string, name: string, amount_base: numeric-string}> $payments */
    private function paymentAmountByCode(array $payments, string $code): string
    {
        return Decimal::normalize(collect($payments)
            ->where('code', $code)
            ->sum('amount_base'), 4);
    }

    /** @return list<array<string, mixed>> */
    private function orderReport(int $companyId, CarbonImmutable $from, CarbonImmutable $to, bool $financial): array
    {
        return array_values(Sale::query()
            ->where('company_id', $companyId)
            ->where('status', SaleStatus::Confirmed)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('delivery_at')
            ->orderByDesc('occurred_at')
            ->get([
                'id', 'number', 'customer_name', 'occurred_at', 'delivery_at',
                'order_status', 'payment_status', 'total_base',
            ])
            ->map(fn (Sale $sale): array => [
                'number' => $sale->number,
                'customer' => $sale->customer_name ?: 'Consumidor final',
                'occurred_at' => $sale->occurred_at,
                'delivery_at' => $sale->delivery_at,
                'order_status' => $sale->order_status->label(),
                'total_base' => $financial ? $sale->total_base : null,
                'payment_status' => $sale->payment_status->label(),
            ])->all());
    }

    /** @return list<array{name: string, quantity: numeric-string, amount_base: numeric-string|null}> */
    private function extraReport(int $companyId, CarbonImmutable $from, CarbonImmutable $to, bool $financial): array
    {
        return array_values(SaleExtraLine::query()
            ->where('company_id', $companyId)
            ->whereHas('sale', fn (Builder $query) => $query
                ->where('status', SaleStatus::Confirmed)
                ->whereBetween('occurred_at', [$from, $to]))
            ->select('extra_name')
            ->selectRaw('SUM(quantity) as quantity')
            ->selectRaw('SUM(subtotal_base) as amount_base')
            ->groupBy('extra_name')
            ->orderByDesc('quantity')
            ->get()
            ->map(fn (SaleExtraLine $line): array => [
                'name' => $line->extra_name,
                'quantity' => Decimal::normalize($line->getAttribute('quantity'), 6),
                'amount_base' => $financial
                    ? Decimal::normalize($line->getAttribute('amount_base'), 4)
                    : null,
            ])->all());
    }

    /**
     * @param  Builder<Sale>  $sales
     * @return list<array{name: string, count: int}>
     */
    private function salesByResponsible(Builder $sales): array
    {
        $rows = (clone $sales)->select('confirmed_by_membership_id')->selectRaw('COUNT(*) as sales_count')
            ->groupBy('confirmed_by_membership_id')->orderByDesc('sales_count')->get();
        $names = Membership::query()->withoutGlobalScope('company')->with('user:id,name')
            ->whereIn('id', $rows->pluck('confirmed_by_membership_id'))->get()->keyBy('id');

        return array_values($rows->map(fn (Sale $sale): array => [
            'name' => $names->get($sale->confirmed_by_membership_id)?->user->name ?? 'Sin responsable',
            'count' => (int) $sale->getAttribute('sales_count'),
        ])->all());
    }

    /** @return array{quantity: numeric-string, top: list<array<string, mixed>>} */
    private function bouquetReport(int $companyId, CarbonImmutable $from, CarbonImmutable $to, bool $financial): array
    {
        $itemsQuery = SaleItem::query()->where('company_id', $companyId)
            ->whereHas('sale', fn (Builder $query) => $query->where('status', SaleStatus::Confirmed)
                ->whereBetween('occurred_at', [$from, $to]));
        $quantity = Decimal::normalize((clone $itemsQuery)->sum('quantity'), 6);
        $items = (clone $itemsQuery)
            ->select('product_name')->selectRaw('SUM(quantity) as quantity')
            ->selectRaw('SUM(subtotal_base) as income_base')->selectRaw('SUM(total_cost_base) as cost_base')
            ->selectRaw('SUM(gross_margin_base) as margin_base')
            ->selectRaw('COUNT(*) as line_count')->selectRaw('COUNT(total_cost_base) as cost_count')
            ->groupBy('product_name')
            ->orderByDesc('quantity')->limit(10)->get();

        return [
            'quantity' => $quantity,
            'top' => array_values($items->map(fn (SaleItem $item): array => [
                'name' => $item->product_name,
                'quantity' => Decimal::normalize($item->quantity, 6),
                'income_base' => $financial ? Decimal::normalize($item->getAttribute('income_base'), 4) : null,
                'cost_base' => $financial && (int) $item->getAttribute('line_count') === (int) $item->getAttribute('cost_count')
                    ? Decimal::normalize($item->getAttribute('cost_base'), 4) : null,
                'margin_base' => $financial && (int) $item->getAttribute('line_count') === (int) $item->getAttribute('cost_count')
                    ? Decimal::normalize($item->getAttribute('margin_base'), 4) : null,
            ])->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function inventoryReport(int $companyId, CarbonImmutable $from, CarbonImmutable $to, bool $financial): array
    {
        $balances = StockBalance::query()->where('company_id', $companyId)->select('product_id')
            ->selectRaw('SUM(quantity) as total_quantity')->groupBy('product_id')->get();
        $trackedProducts = Product::query()->where('company_id', $companyId)->selfManaged()
            ->where('is_active', true)->count();
        $withStock = $balances->filter(fn (StockBalance $balance): bool => bccomp(
            Decimal::normalize($balance->getAttribute('total_quantity'), 6), '0', 6,
        ) === 1)->count();

        $movementLines = fn (array $types): Builder => StockMovementLine::query()
            ->where('company_id', $companyId)->whereHas('movement', fn (Builder $query) => $query
            ->whereIn('type', $types)->whereBetween('occurred_at', [$from, $to]));
        $purchases = $movementLines([StockMovementType::AdjustmentIn]);
        $outbound = $movementLines([
            StockMovementType::AdjustmentOut, StockMovementType::Sale, StockMovementType::SaleRegularization,
        ]);
        $waste = $movementLines([StockMovementType::Waste]);
        $mostConsumed = $movementLines([
            StockMovementType::AdjustmentOut, StockMovementType::Sale, StockMovementType::SaleRegularization, StockMovementType::Waste,
        ])->with('product:id,name')->select('product_id')->selectRaw('SUM(ABS(quantity)) as consumed_quantity')
            ->groupBy('product_id')->orderByDesc('consumed_quantity')->limit(10)->get();

        return [
            'current_value_base' => $financial ? Decimal::normalize(
                StockBalance::query()->where('company_id', $companyId)->sum('inventory_value_base'), 4,
            ) : null,
            'products_with_stock' => $withStock,
            'products_without_stock' => max(0, $trackedProducts - $withStock),
            'low_stock_count' => null,
            'purchase_movements' => StockMovement::query()->where('company_id', $companyId)
                ->where('type', StockMovementType::AdjustmentIn)->whereBetween('occurred_at', [$from, $to])->count(),
            'purchased_quantity' => Decimal::normalize((clone $purchases)->sum('quantity'), 6),
            'purchase_cost_base' => $financial ? Decimal::normalize((clone $purchases)->sum('total_cost_base'), 4) : null,
            'outbound_quantity' => $this->absoluteQuantity($outbound),
            'waste_quantity' => $this->absoluteQuantity($waste),
            'most_consumed' => $mostConsumed->map(fn (StockMovementLine $line): array => [
                'name' => $line->product->name,
                'quantity' => Decimal::normalize($line->getAttribute('consumed_quantity'), 6),
            ])->all(),
        ];
    }

    /**
     * @param  Builder<Expense>  $expenses
     * @return array<string, mixed>
     */
    private function expenseReport(Builder $expenses, string $total): array
    {
        $rows = (clone $expenses)->get();

        return [
            'total_base' => $total,
            'with_invoice_base' => Decimal::normalize(
                $rows->where('receipt_type', ExpenseReceiptType::WithInvoice)->sum('amount_base'), 4,
            ),
            'without_invoice_base' => Decimal::normalize(
                $rows->where('receipt_type', ExpenseReceiptType::WithoutInvoice)->sum('amount_base'), 4,
            ),
            'by_category' => $this->groupExpenses($rows, 'category_name'),
            'by_payment_method' => $this->groupExpenses($rows, 'payment_method_name', 'Sin método registrado'),
            'by_responsible' => $rows->groupBy('membership_id')->map(function (Collection $group): array {
                $expense = $group->first();

                if (! $expense instanceof Expense) {
                    throw new DomainException('No se pudo identificar al responsable del gasto.');
                }

                $expense->loadMissing('responsible.user:id,name');

                return [
                    'name' => $expense->responsible->user->name,
                    'amount_base' => Decimal::normalize($group->sum('amount_base'), 4),
                ];
            })->sortByDesc('amount_base')->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @return list<array{name: string, amount_base: string}>
     */
    private function groupExpenses(Collection $expenses, string $field, string $fallback = 'Sin categoría'): array
    {
        return array_values($expenses->groupBy(fn (Expense $expense): string => (string) ($expense->getAttribute($field) ?: $fallback))
            ->map(fn (Collection $group, string $name): array => [
                'name' => $name,
                'amount_base' => Decimal::normalize($group->sum('amount_base'), 4),
            ])->sortByDesc('amount_base')->all());
    }

    /** @param Builder<StockMovementLine> $lines */
    private function absoluteQuantity(Builder $lines): string
    {
        $total = (clone $lines)->selectRaw('COALESCE(SUM(ABS(quantity)), 0) as aggregate')
            ->value('aggregate');

        return Decimal::normalize($total ?? 0, 6);
    }
}
