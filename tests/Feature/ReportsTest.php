<?php

use App\Actions\Users\ConfigureWorkerAccess;
use App\Enums\ExpenseStatus;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Reports\BusinessReports;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

test('owner can view reports', function () {
    $context = expenseContext('Reportes owner');

    $this->actingAs($context['owner'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Resumen general')
        ->assertSee('Resultado estimado');
});

test('worker without report permission cannot access reports', function () {
    $context = expenseContext('Reportes restringidos');
    $workerUser = User::factory()->create();
    $worker = Membership::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $workerUser->getKey(),
        'status' => MembershipStatus::Active,
        'is_owner' => false,
    ]);

    $this->actingAs($workerUser)
        ->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('reports.index'))
        ->assertForbidden();
});

test('sales report permission does not reveal financial metrics', function () {
    $context = expenseContext('Reportes sin finanzas');
    $workerUser = User::factory()->create();
    $worker = Membership::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $workerUser->getKey(),
        'status' => MembershipStatus::Active,
        'is_owner' => false,
    ]);
    app(ConfigureWorkerAccess::class)->handle(
        $context['membership'],
        $worker,
        'Personalizado',
        ['reports.sales.view'],
    );

    $this->actingAs($workerUser)
        ->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Número de ventas')
        ->assertDontSee('Total vendido')
        ->assertDontSee('Costo de ramos vendidos')
        ->assertDontSee('Resultado estimado');
});

test('reports isolate every company', function () {
    $companyA = expenseContext('Empresa A');
    $companyB = expenseContext('Empresa B');
    Sale::factory()->create([
        'company_id' => $companyA['company']->getKey(),
        'confirmed_by_membership_id' => $companyA['membership']->getKey(),
        'total_base' => 100,
        'balance_due_base' => 100,
    ]);
    Sale::factory()->create([
        'company_id' => $companyB['company']->getKey(),
        'confirmed_by_membership_id' => $companyB['membership']->getKey(),
        'total_base' => 900,
        'balance_due_base' => 900,
    ]);

    $report = app(BusinessReports::class)->summary($companyA['membership']);

    expect($report['general']['sales_count'])->toBe(1)
        ->and($report['general']['total_sold_base'])->toBe('100.0000');
});

test('voided sales do not count as sales or income', function () {
    $context = expenseContext('Ventas anuladas');
    Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'total_base' => 80,
        'balance_due_base' => 80,
    ]);
    Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'status' => SaleStatus::Voided,
        'total_base' => 500,
        'balance_due_base' => 0,
    ]);

    $report = app(BusinessReports::class)->summary($context['membership']);

    expect($report['sales']['count'])->toBe(1)
        ->and($report['sales']['voided_count'])->toBe(1)
        ->and($report['sales']['total_sold_base'])->toBe('80.0000');
});

test('cancelled expenses do not count', function () {
    $context = expenseContext('Gastos anulados');
    Expense::factory()->create([
        'company_id' => $context['company']->getKey(),
        'membership_id' => $context['membership']->getKey(),
        'amount_base' => 40,
        'status' => ExpenseStatus::Confirmed,
    ]);
    Expense::factory()->create([
        'company_id' => $context['company']->getKey(),
        'membership_id' => $context['membership']->getKey(),
        'amount_base' => 90,
        'status' => ExpenseStatus::Cancelled,
        'cancelled_by_membership_id' => $context['membership']->getKey(),
        'cancelled_at' => now(),
        'cancellation_reason' => 'Duplicado',
    ]);

    $report = app(BusinessReports::class)->summary($context['membership']);

    expect($report['expenses']['total_base'])->toBe('40.0000');
});

test('partial payments separate sold collected and pending amounts', function () {
    $context = expenseContext('Pagos parciales');
    $sale = Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'total_base' => 100,
        'paid_total_base' => 40,
        'balance_due_base' => 60,
        'payment_status' => PaymentStatus::Partial,
    ]);
    SalePayment::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'received_by_membership_id' => $context['membership']->getKey(),
        'amount_base' => 40,
        'occurred_at' => now(),
    ]);

    $report = app(BusinessReports::class)->summary($context['membership']);

    expect($report['general']['total_sold_base'])->toBe('100.0000')
        ->and($report['general']['collected_base'])->toBe('40.0000')
        ->and($report['general']['pending_base'])->toBe('60.0000');
});

test('historical bouquet cost does not change with current inventory costs', function () {
    $context = expenseContext('Costo histórico');
    $sale = Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'total_base' => 100,
        'total_cost_base' => 35,
        'gross_margin_base' => 65,
    ]);
    $item = SaleItem::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'product_name' => 'Ramo histórico',
        'total_cost_base' => 35,
        'gross_margin_base' => 65,
    ]);
    $supply = Product::factory()->create(['company_id' => $context['company']->getKey()]);
    StockBalance::factory()->create([
        'company_id' => $context['company']->getKey(),
        'product_id' => $supply->getKey(),
        'average_unit_cost_base' => 999,
        'inventory_value_base' => 999,
    ]);

    $report = app(BusinessReports::class)->summary($context['membership']);

    expect($report['general']['historical_cost_base'])->toBe('35.0000')
        ->and($report['bouquets']['top'][0]['cost_base'])->toBe('35.0000')
        ->and($item->total_cost_base)->toBe('35.0000');
});

test('today week and month filters use the company timezone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 12:00:00', 'America/La_Paz'));
    $context = expenseContext('Períodos reportes');

    foreach (['2026-08-18 09:00:00', '2026-08-17 09:00:00', '2026-08-02 09:00:00'] as $occurredAt) {
        Sale::factory()->create([
            'company_id' => $context['company']->getKey(),
            'confirmed_by_membership_id' => $context['membership']->getKey(),
            'occurred_at' => CarbonImmutable::parse($occurredAt, 'America/La_Paz')->utc(),
        ]);
    }

    $reports = app(BusinessReports::class);

    expect($reports->summary($context['membership'], 'today')['general']['sales_count'])->toBe(1)
        ->and($reports->summary($context['membership'], 'week')['general']['sales_count'])->toBe(2)
        ->and($reports->summary($context['membership'], 'month')['general']['sales_count'])->toBe(3);

    CarbonImmutable::setTestNow();
});

test('inventory report calculates current company inventory value', function () {
    $context = expenseContext('Valor inventario');
    StockBalance::factory()->create([
        'company_id' => $context['company']->getKey(),
        'quantity' => 10,
        'inventory_value_base' => 50,
    ]);
    StockBalance::factory()->create([
        'company_id' => $context['company']->getKey(),
        'quantity' => 4,
        'inventory_value_base' => 28,
    ]);

    $report = app(BusinessReports::class)->summary($context['membership']);

    expect($report['inventory']['current_value_base'])->toBe('78.0000')
        ->and($report['inventory']['products_with_stock'])->toBe(2);
});
