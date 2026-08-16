<?php

namespace App\Services\Dashboard;

use App\Enums\ExpenseStatus;
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

        $sales = Sale::query()->where('status', SaleStatus::Confirmed)
            ->whereBetween('occurred_at', [$from, $to]);

        if (! $canViewIncome) {
            $sales->where('confirmed_by_membership_id', $membership->getKey());
        }

        $saleIds = (clone $sales)->pluck('id');
        $salesCount = $saleIds->count();
        $income = $canViewIncome ? Decimal::normalize((clone $sales)->sum('total_base'), 4) : null;
        $cost = $canViewCosts ? Decimal::normalize((clone $sales)->sum('total_cost_base'), 4) : null;
        $grossMargin = $canViewProfit ? Decimal::normalize((clone $sales)->sum('gross_margin_base'), 4) : null;
        $expenses = $canViewExpenses ? Decimal::normalize(Expense::query()
            ->where('status', ExpenseStatus::Confirmed)->whereBetween('occurred_at', [$from, $to])->sum('amount_base'), 4) : null;

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'visibility' => compact('canViewIncome', 'canViewCosts', 'canViewProfit', 'canViewExpenses'),
            'sales_count' => $salesCount,
            'bouquets_sold' => (string) SaleItem::query()->whereIn('sale_id', $saleIds)->sum('quantity'),
            'income_base' => $income,
            'average_ticket_base' => $canViewIncome && $salesCount > 0 ? bcdiv($income, (string) $salesCount, 4) : ($canViewIncome ? '0.0000' : null),
            'cost_base' => $cost,
            'gross_margin_base' => $grossMargin,
            'expenses_base' => $expenses,
            'approximate_result_base' => $canViewIncome && $canViewCosts && $canViewExpenses
                ? bcsub(bcsub($income, $cost, 4), $expenses, 4) : null,
            'payments' => $canViewIncome ? SalePayment::query()->whereIn('sale_id', $saleIds)
                ->selectRaw('payment_method_name, SUM(amount_base) as total_base')
                ->groupBy('payment_method_name')->orderByDesc('total_base')->pluck('total_base', 'payment_method_name')->all() : [],
            'mixed_sales_count' => $canViewIncome ? Sale::query()->whereIn('id', $saleIds)->has('payments', '>', 1)->count() : null,
            'top_bouquets' => SaleItem::query()->whereIn('sale_id', $saleIds)
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

        return StockBalance::query()->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->where('quantity', '<=', 0)->count();
    }
}
