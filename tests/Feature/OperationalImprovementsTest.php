<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RecordManualInbound;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Sales\UpdateSaleOrderStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Enums\PaymentStatus;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleOrderStatus;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleInventoryPending;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\UpcomingDeliveries;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array<string, mixed> */
function operationalContext(string $name = 'Florería operaciones'): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => $name,
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())
        ->where('user_id', $owner->getKey())
        ->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory, ModuleCode::Sales] as $moduleCode) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', $moduleCode)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create([
        'company_id' => $company->getKey(),
        'code' => fake()->unique()->bothify('UND-###'),
    ]);
    $supply = Product::factory()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
        'is_sellable' => false,
        'sku' => fake()->unique()->bothify('INS-###'),
    ]);

    return compact('owner', 'company', 'membership', 'branch', 'warehouse', 'unit', 'supply');
}

function operationalSale(array $context, CarbonImmutable $deliveryAt, array $attributes = []): Sale
{
    return Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'warehouse_id' => $context['warehouse']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'delivery_at' => $deliveryAt->utc(),
        ...$attributes,
    ]);
}

function addBouquetsToOperationalSale(array $context, Sale $sale, int|float|string $quantity): SaleItem
{
    return SaleItem::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'quantity' => $quantity,
    ]);
}

test('A-C a paid reserved sale with pending inventory can be delivered without side effects', function (string $deliveryAt) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 12:00:00', 'America/La_Paz'));
    $context = operationalContext();
    $sale = operationalSale($context, CarbonImmutable::parse($deliveryAt, 'America/La_Paz'), [
        'order_status' => SaleOrderStatus::Reserved,
        'payment_status' => PaymentStatus::Paid,
        'inventory_status' => SaleInventoryStatus::PendingRegularization,
        'total_base' => 100,
        'paid_total_base' => 100,
        'balance_due_base' => 0,
        'total_cost_base' => null,
        'gross_margin_base' => null,
    ]);
    $item = addBouquetsToOperationalSale($context, $sale, 1);
    $pending = SaleInventoryPending::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'sale_item_id' => $item->getKey(),
        'warehouse_id' => $context['warehouse']->getKey(),
        'original_component_product_id' => $context['supply']->getKey(),
    ]);
    SalePayment::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'received_by_membership_id' => $context['membership']->getKey(),
        'amount_base' => 100,
    ]);
    $before = [
        'movements' => StockMovement::query()->count(),
        'balances' => StockBalance::query()->count(),
        'payments' => $sale->payments()->count(),
        'pending_updated_at' => $pending->updated_at,
    ];

    app(UpdateSaleOrderStatus::class)->handle(
        $context['membership'], $sale, SaleOrderStatus::Delivered,
    );

    expect($sale->refresh()->order_status)->toBe(SaleOrderStatus::Delivered)
        ->and($sale->inventory_status)->toBe(SaleInventoryStatus::PendingRegularization)
        ->and($pending->refresh()->status->value)->toBe('pending')
        ->and($pending->updated_at->equalTo($before['pending_updated_at']))->toBeTrue()
        ->and(StockMovement::query()->count())->toBe($before['movements'])
        ->and(StockBalance::query()->count())->toBe($before['balances'])
        ->and($sale->payments()->count())->toBe($before['payments'])
        ->and($sale->paid_total_base)->toBe('100.0000');
    CarbonImmutable::setTestNow();
})->with([
    'antes de la entrega' => '2026-09-24 08:00:00',
    'el mismo día' => '2026-09-23 18:00:00',
    'después de la entrega' => '2026-09-22 08:00:00',
]);

test('M an order exactly one hour away generates an upcoming delivery alert', function () {
    $now = CarbonImmutable::parse('2026-09-23 07:00:00', 'America/La_Paz');
    $context = operationalContext();
    $sale = operationalSale($context, $now->addHour(), ['number' => 'V-UNA-HORA']);
    addBouquetsToOperationalSale($context, $sale, 1);

    $groups = app(UpcomingDeliveries::class)->forMembership($context['membership'], $now);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['orders'][0]['number'])->toBe('V-UNA-HORA')
        ->and($groups[0]['overdue'])->toBeFalse();
});

test('N deliveries at the same time are grouped with their total bouquet workload', function () {
    $now = CarbonImmutable::parse('2026-09-23 07:30:00', 'America/La_Paz');
    $context = operationalContext();
    foreach ([['V-GRUPO-1', 2], ['V-GRUPO-2', 3]] as [$number, $quantity]) {
        $sale = operationalSale($context, $now->addMinutes(30), ['number' => $number]);
        addBouquetsToOperationalSale($context, $sale, $quantity);
    }

    $group = collect(app(UpcomingDeliveries::class)->forMembership($context['membership'], $now))->sole();

    expect($group['orders_count'])->toBe(2)
        ->and($group['bouquets_quantity'])->toBe('5.000000')
        ->and(collect($group['orders'])->pluck('number'))->toContain('V-GRUPO-1', 'V-GRUPO-2');
});

test('O an overdue undelivered order remains visible and highlighted', function () {
    $now = CarbonImmutable::parse('2026-09-23 09:00:00', 'America/La_Paz');
    $context = operationalContext();
    $sale = operationalSale($context, $now->subHours(2), [
        'number' => 'V-VENCIDA',
        'order_status' => SaleOrderStatus::Preparing,
    ]);
    addBouquetsToOperationalSale($context, $sale, 1);

    $group = collect(app(UpcomingDeliveries::class)->forMembership($context['membership'], $now))->sole();

    expect($group['overdue'])->toBeTrue()
        ->and($group['orders'][0]['number'])->toBe('V-VENCIDA');
});

test('P a delivered order does not generate an alert', function () {
    $now = CarbonImmutable::parse('2026-09-23 09:00:00', 'America/La_Paz');
    $context = operationalContext();
    $sale = operationalSale($context, $now->addMinutes(30), [
        'order_status' => SaleOrderStatus::Delivered,
    ]);
    addBouquetsToOperationalSale($context, $sale, 1);

    expect(app(UpcomingDeliveries::class)->forMembership($context['membership'], $now))->toBe([]);
});

test('Q pending inventory does not prevent a delivery alert', function () {
    $now = CarbonImmutable::parse('2026-09-23 09:00:00', 'America/La_Paz');
    $context = operationalContext();
    $sale = operationalSale($context, $now->addMinutes(30), [
        'number' => 'V-PENDIENTE',
        'inventory_status' => SaleInventoryStatus::PendingRegularization,
        'total_cost_base' => null,
        'gross_margin_base' => null,
    ]);
    addBouquetsToOperationalSale($context, $sale, 1);

    $groups = app(UpcomingDeliveries::class)->forMembership($context['membership'], $now);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['orders'][0]['number'])->toBe('V-PENDIENTE');
});

test('R delivery alerts are isolated by company', function () {
    $now = CarbonImmutable::parse('2026-09-23 09:00:00', 'America/La_Paz');
    $first = operationalContext('Primera alertas');
    $second = operationalContext('Segunda alertas');
    $firstSale = operationalSale($first, $now->addMinutes(20), ['number' => 'V-EMPRESA-A']);
    $secondSale = operationalSale($second, $now->addMinutes(20), ['number' => 'V-EMPRESA-B']);
    addBouquetsToOperationalSale($first, $firstSale, 1);
    addBouquetsToOperationalSale($second, $secondSale, 1);

    $numbers = collect(app(UpcomingDeliveries::class)->forMembership($first['membership'], $now))
        ->flatMap(fn (array $group): array => $group['orders'])
        ->pluck('number');

    expect($numbers)->toContain('V-EMPRESA-A')->not->toContain('V-EMPRESA-B');
});

test('S and U a historical inbound stays on the effective local day and updates the official ledger', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 12:00:00', 'America/La_Paz'));
    $context = operationalContext();
    $effectiveDate = CarbonImmutable::parse('2026-09-15 00:00:00', 'America/La_Paz');

    $movement = app(RecordManualInbound::class)->handle(
        $context['membership'], $context['warehouse'], $context['supply'], 10, 5, 'Compra histórica', $effectiveDate,
    );
    $balance = StockBalance::query()->where('warehouse_id', $context['warehouse']->getKey())
        ->where('product_id', $context['supply']->getKey())->sole();

    expect($movement->occurred_at->timezone('America/La_Paz')->format('Y-m-d H:i'))->toBe('2026-09-15 00:00')
        ->and($movement->created_at->timezone('America/La_Paz')->toDateString())->toBe('2026-09-23')
        ->and($movement->lines)->toHaveCount(1)
        ->and($balance->quantity)->toBe('10.000000')
        ->and($balance->average_unit_cost_base)->toBe('5.0000')
        ->and($balance->inventory_value_base)->toBe('50.0000');
    CarbonImmutable::setTestNow();
});

test('T a future inventory effective date is rejected without changing stock', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 12:00:00', 'America/La_Paz'));
    $context = operationalContext();

    expect(fn () => app(RecordManualInbound::class)->handle(
        $context['membership'],
        $context['warehouse'],
        $context['supply'],
        10,
        5,
        'Compra futura',
        CarbonImmutable::parse('2026-09-24 00:00:00', 'America/La_Paz'),
    ))->toThrow(DomainException::class, 'no puede ser futura');

    expect(StockMovement::query()->count())->toBe(0)
        ->and(StockBalance::query()->count())->toBe(0);
    CarbonImmutable::setTestNow();
});

test('V a historical inbound never regularizes pending sale inventory automatically', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 12:00:00', 'America/La_Paz'));
    $context = operationalContext();
    $sale = operationalSale($context, now('America/La_Paz')->addDay()->toImmutable(), [
        'inventory_status' => SaleInventoryStatus::PendingRegularization,
        'total_cost_base' => null,
        'gross_margin_base' => null,
    ]);
    $item = addBouquetsToOperationalSale($context, $sale, 1);
    $pending = SaleInventoryPending::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'sale_item_id' => $item->getKey(),
        'warehouse_id' => $context['warehouse']->getKey(),
        'original_component_product_id' => $context['supply']->getKey(),
    ]);

    app(RecordManualInbound::class)->handle(
        $context['membership'],
        $context['warehouse'],
        $context['supply'],
        10,
        5,
        'Compra histórica',
        CarbonImmutable::parse('2026-09-15 00:00:00', 'America/La_Paz'),
    );

    expect($sale->refresh()->inventory_status)->toBe(SaleInventoryStatus::PendingRegularization)
        ->and($pending->refresh()->status->value)->toBe('pending')
        ->and($pending->stock_movement_id)->toBeNull();
    CarbonImmutable::setTestNow();
});
