<?php

use App\Actions\Expenses\CancelExpense;
use App\Actions\Expenses\CreateExpense;
use App\Actions\Users\CreateWorker;
use App\Enums\SaleStatus;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Dashboard\BusinessDashboard;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

test('owner dashboard subtracts confirmed expenses and excludes cancelled ones', function () {
    $context = expenseContext('Dashboard financiero');
    $category = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();
    $confirmed = app(CreateExpense::class)->handle($context['membership'], $category, 40, 'Alquiler');
    $cancelled = app(CreateExpense::class)->handle($context['membership'], $category, 25, 'Duplicado');
    app(CancelExpense::class)->handle($context['membership'], $cancelled, 'Duplicado');

    $summary = app(BusinessDashboard::class)->summary($context['membership'], 'month');

    expect($summary['expenses_base'])->toBe('40.0000')
        ->and($summary['sales_count'])->toBe(0)
        ->and($confirmed->status->value)->toBe('confirmed');
});

test('owner dashboard uses confirmed sale snapshots for income cost and margin', function () {
    $context = expenseContext('Dashboard ventas');
    $sale = Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'total_base' => 100,
        'total_cost_base' => 55,
        'gross_margin_base' => 45,
        'occurred_at' => now(),
    ]);
    SaleItem::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'product_name' => 'Ramo Amor',
        'quantity' => 2,
        'subtotal_base' => 100,
    ]);
    Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'status' => SaleStatus::Voided,
        'total_base' => 999,
        'occurred_at' => now(),
    ]);

    $summary = app(BusinessDashboard::class)->summary($context['membership']);

    expect($summary['sales_count'])->toBe(1)
        ->and($summary['bouquets_sold'])->toEqual('2')
        ->and($summary['income_base'])->toBe('100.0000')
        ->and($summary['average_ticket_base'])->toBe('100.0000')
        ->and($summary['cost_base'])->toBe('55.0000')
        ->and($summary['gross_margin_base'])->toBe('45.0000')
        ->and($summary['approximate_result_base'])->toBe('45.0000')
        ->and($summary['top_bouquets']->sole()->product_name)->toBe('Ramo Amor');
});

test('seller dashboard is limited to own activity and hides financial figures', function () {
    $context = expenseContext('Dashboard trabajador');
    $sellerRole = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $seller = app(CreateWorker::class)->handle($context['membership'], $sellerRole, [
        'name' => 'Vendedora', 'email' => 'dashboard.worker@example.com', 'password' => 'Temporary-Password-123!',
    ]);
    Sale::factory()->create(['company_id' => $context['company']->getKey(), 'confirmed_by_membership_id' => $seller->getKey(), 'occurred_at' => now()]);
    Sale::factory()->create(['company_id' => $context['company']->getKey(), 'confirmed_by_membership_id' => $context['membership']->getKey(), 'occurred_at' => now()]);

    $summary = app(BusinessDashboard::class)->summary($seller);

    expect($summary['sales_count'])->toBe(1)
        ->and($summary['income_base'])->toBeNull()
        ->and($summary['cost_base'])->toBeNull()
        ->and($summary['gross_margin_base'])->toBeNull()
        ->and($summary['expenses_base'])->toBeNull();
});

test('dashboard periods use company timezone for today week and month', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 12:00:00', 'America/La_Paz'));
    $context = expenseContext('Períodos');

    foreach (['2026-08-12 09:00:00', '2026-08-10 09:00:00', '2026-08-02 09:00:00'] as $occurredAt) {
        Sale::factory()->create([
            'company_id' => $context['company']->getKey(),
            'confirmed_by_membership_id' => $context['membership']->getKey(),
            'occurred_at' => CarbonImmutable::parse($occurredAt, 'America/La_Paz')->utc(),
        ]);
    }

    $dashboard = app(BusinessDashboard::class);
    expect($dashboard->summary($context['membership'], 'today')['sales_count'])->toBe(1)
        ->and($dashboard->summary($context['membership'], 'week')['sales_count'])->toBe(2)
        ->and($dashboard->summary($context['membership'], 'month')['sales_count'])->toBe(3);

    CarbonImmutable::setTestNow();
});
