<?php

namespace App\Services\Dashboard;

use App\Enums\ExpenseStatus;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleStatus;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BusinessDashboard
{
    public function __construct(private CompanyAccess $access) {}

    /** @return array<string, mixed> */
    public function summary(Membership $membership, string $period = 'today', ?int $warehouseId = null): array
    {
        [$from, $to] = $this->range($membership, $period);
        $user = $membership->user;
        $canViewIncome = $this->access->allows($user, $membership->company, 'finance.sales_income.view', mutation: false);
        $canViewCosts = $this->access->allows($user, $membership->company, 'sales.costs.view', mutation: false);
        $canViewProfit = $this->access->allows($user, $membership->company, 'sales.profits.view', mutation: false);
        $canViewExpenses = $this->access->allows($user, $membership->company, 'finance.expenses.view', mutation: false);
        $canViewBalances = $this->access->allows($user, $membership->company, 'sales.balances.view', mutation: false);

        $sales = Sale::query()->where('company_id', $membership->company_id)
            ->where('status', SaleStatus::Confirmed)
            ->whereBetween('occurred_at', [$from, $to]);

        if (! $canViewIncome) {
            $sales->where('confirmed_by_membership_id', $membership->getKey());
        }

        $saleIds = (clone $sales)->pluck('id');
        $hasIncompleteCosts = (clone $sales)
            ->where('inventory_status', SaleInventoryStatus::PendingRegularization)
            ->exists();
        $salesCount = $saleIds->count();
        $income = $canViewIncome ? Decimal::normalize((clone $sales)->sum('total_base'), 4) : null;
        $collectionsQuery = SalePayment::query()
            ->where('company_id', $membership->company_id)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereHas('sale', fn (Builder $query) => $query->where('status', SaleStatus::Confirmed));
        $collected = $canViewIncome ? Decimal::normalize((clone $collectionsQuery)->sum('amount_base'), 4) : null;
        $receivable = $canViewBalances ? Decimal::normalize(Sale::query()
            ->where('company_id', $membership->company_id)
            ->where('status', SaleStatus::Confirmed)
            ->sum('balance_due_base'), 4) : null;
        $cost = $canViewCosts && ! $hasIncompleteCosts
            ? Decimal::normalize((clone $sales)->sum('total_cost_base'), 4) : null;
        $grossMargin = $canViewProfit && ! $hasIncompleteCosts
            ? Decimal::normalize((clone $sales)->sum('gross_margin_base'), 4) : null;
        $expenses = $canViewExpenses ? Decimal::normalize(Expense::query()
            ->where('company_id', $membership->company_id)
            ->where('status', ExpenseStatus::Confirmed)->whereBetween('occurred_at', [$from, $to])->sum('amount_base'), 4) : null;

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'visibility' => compact('canViewIncome', 'canViewCosts', 'canViewProfit', 'canViewExpenses', 'canViewBalances'),
            'sales_count' => $salesCount,
            'bouquets_sold' => (string) SaleItem::query()->where('company_id', $membership->company_id)->whereIn('sale_id', $saleIds)->sum('quantity'),
            'income_base' => $income,
            'sales_booked_base' => $income,
            'collected_base' => $collected,
            'receivable_base' => $receivable,
            'average_ticket_base' => $canViewIncome && $salesCount > 0 ? bcdiv($income, (string) $salesCount, 4) : ($canViewIncome ? '0.0000' : null),
            'cost_base' => $cost,
            'gross_margin_base' => $grossMargin,
            'expenses_base' => $expenses,
            'approximate_result_base' => $canViewIncome && $canViewCosts && $canViewExpenses && $cost !== null
                ? bcsub(bcsub($income, $cost, 4), $expenses, 4) : null,
            'cash_result_base' => $canViewIncome && $canViewExpenses
                ? bcsub($collected, $expenses, 4) : null,
            'payments' => $canViewIncome ? (clone $collectionsQuery)
                ->selectRaw('payment_method_name, SUM(amount_base) as total_base')
                ->groupBy('payment_method_name')->orderByDesc('total_base')->pluck('total_base', 'payment_method_name')->all() : [],
            'mixed_sales_count' => $canViewIncome ? Sale::query()->where('company_id', $membership->company_id)->whereIn('id', $saleIds)->has('payments', '>', 1)->count() : null,
            'top_bouquets' => SaleItem::query()->where('company_id', $membership->company_id)->whereIn('sale_id', $saleIds)
                ->selectRaw('product_name, SUM(quantity) as quantity, SUM(subtotal_base) as total_base')
                ->groupBy('product_name')->orderByDesc('quantity')->limit(5)->get(),
            'recent_sales' => (clone $sales)->with(['items:id,sale_id,product_name,quantity', 'payments:id,sale_id,payment_method_name,amount_base', 'confirmedBy.user:id,name'])
                ->latest('occurred_at')->limit(5)->get(),
            'stock_alerts' => $this->stockAlerts($membership, $warehouseId),
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function range(Membership $membership, string $period): array
    {
        $now = CarbonImmutable::now($membership->company->timezone);
        $from = match ($period) {
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            default => $now->startOfDay(),
        };

        return [$from->utc(), $now->endOfDay()->utc()];
    }

    private function stockAlerts(Membership $membership, ?int $warehouseId): int
    {
        if (! $this->access->allows($membership->user, $membership->company, 'inventory.view', mutation: false)) {
            return 0;
        }

        return StockBalance::query()->where('company_id', $membership->company_id)
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->where('quantity', '<=', 0)->count();
    }
}
