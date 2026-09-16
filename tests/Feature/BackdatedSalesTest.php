<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Companies\UpdateSalesSettings;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Memberships\AddMembership;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Roles\AssignRole;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\RegisterSalePayment;
use App\Enums\CompanyModuleStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\BusinessReports;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-16 09:00:00', 'America/La_Paz'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return array{owner: User, company: Company, membership: Membership, branch: Branch, warehouse: Warehouse, supply: Product, bouquet: Product, cash: PaymentMethod}
 */
function backdatedSalesContext(string $stock = '20'): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => fake()->unique()->company(),
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
        'name' => 'Rosa atrasada',
        'sku' => fake()->unique()->bothify('ATR-###'),
        'is_sellable' => false,
        'fallback_unit_cost_base' => 5,
    ]);

    if (bccomp($stock, '0', 6) === 1) {
        app(RegisterOpeningStock::class)->handle($membership, $warehouse, $supply, $stock, 5);
    }

    $bouquet = Product::factory()->composed()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
        'name' => 'Ramo atrasado',
        'sku' => fake()->unique()->bothify('RAM-ATR-###'),
        'is_sellable' => true,
        'sale_price_base' => 80,
    ]);
    app(CreateProductRecipe::class)->handle(
        $membership,
        $bouquet,
        1,
        [['product' => $supply, 'quantity' => 2]],
    );
    $cash = PaymentMethod::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())
        ->where('code', 'cash')
        ->firstOrFail();

    return compact('owner', 'company', 'membership', 'branch', 'warehouse', 'supply', 'bouquet', 'cash');
}

/** @param array<int, array{payment_method: PaymentMethod, amount_base: int|float|string}> $payments */
function createBackdatedTestSale(array $context, ?CarbonImmutable $occurredAt = null, array $payments = []): Sale
{
    return app(ConfirmSale::class)->handle(
        $context['membership'],
        $context['branch'],
        $context['warehouse'],
        [['product' => $context['bouquet'], 'quantity' => 1]],
        $payments,
        occurredAt: $occurredAt,
        orderStatus: SaleOrderStatus::Preparing,
    );
}

function enableBackdatedTestSales(array $context): void
{
    app(UpdateSalesSettings::class)->handle($context['membership'], true);
}

test('A configuration off records a normal sale at the current time', function () {
    $context = backdatedSalesContext();
    $sale = createBackdatedTestSale($context);

    expect($sale->occurred_at->equalTo(now()))->toBeTrue();
});

test('B configuration off rejects an explicitly manipulated date', function () {
    $context = backdatedSalesContext();

    expect(fn () => createBackdatedTestSale($context, now()->subDay()))
        ->toThrow(DomainException::class, 'no permite registrar ventas');
});

test('C configuration on permits a sale from yesterday', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $occurredAt = CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc();

    expect(createBackdatedTestSale($context, $occurredAt)->occurred_at->equalTo($occurredAt))->toBeTrue();
});

test('D configuration on permits the beginning of the second previous calendar day', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $occurredAt = CarbonImmutable::parse('2026-09-14 00:00:00', 'America/La_Paz')->utc();

    expect(createBackdatedTestSale($context, $occurredAt)->occurred_at->equalTo($occurredAt))->toBeTrue();
});

test('E a sale older than two local calendar days is rejected', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);

    expect(fn () => createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-13 23:59:59', 'America/La_Paz')->utc(),
    ))->toThrow(DomainException::class, 'anterior a 2 días');
});

test('F a future sale is rejected', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);

    expect(fn () => createBackdatedTestSale($context, now()->addMinute()))
        ->toThrow(DomainException::class, 'futuro');
});

test('G a backdated sale with stock uses the official inventory ledger', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $occurredAt = CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc();
    $sale = createBackdatedTestSale($context, $occurredAt);
    $movement = $sale->stockMovementLinks()->sole()->stockMovement;
    $balance = StockBalance::query()->where('warehouse_id', $context['warehouse']->getKey())
        ->where('product_id', $context['supply']->getKey())->sole();

    expect($sale->inventory_status)->toBe(SaleInventoryStatus::Complete)
        ->and($movement->type)->toBe(StockMovementType::Sale)
        ->and($movement->occurred_at->equalTo($occurredAt))->toBeTrue()
        ->and($movement->created_at->equalTo(now()))->toBeTrue()
        ->and($balance->quantity)->toBe('18.000000');
});

test('H a backdated sale without sufficient stock creates a complete pending instead of partial consumption', function () {
    $context = backdatedSalesContext('1');
    enableBackdatedTestSales($context);
    $sale = createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc(),
    );

    expect($sale->inventory_status)->toBe(SaleInventoryStatus::PendingRegularization)
        ->and($sale->inventoryPendings)->toHaveCount(1)
        ->and($sale->inventoryPendings->sole()->required_quantity)->toBe('2.000000')
        ->and($sale->stockMovementLinks)->toHaveCount(0)
        ->and(StockBalance::query()->where('warehouse_id', $context['warehouse']->getKey())
            ->where('product_id', $context['supply']->getKey())->value('quantity'))->toBe('1.000000');
});

test('I and J reports include the sale on its effective day and not its registration day', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc(),
    );
    $reports = app(BusinessReports::class);

    expect($reports->summary($context['membership'], 'custom', '2026-09-15', '2026-09-15')['general']['sales_count'])->toBe(1)
        ->and($reports->summary($context['membership'], 'custom', '2026-09-16', '2026-09-16')['general']['sales_count'])->toBe(0);
});

test('K created at keeps the real registration moment', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $sale = createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc(),
    );

    expect($sale->created_at->equalTo(now()))->toBeTrue()
        ->and($sale->created_at->toDateString())->not->toBe($sale->occurred_at->toDateString());
});

test('L an operational worker cannot update or enter backdated sale settings', function () {
    $context = backdatedSalesContext();
    $workerUser = User::factory()->create();
    $worker = app(AddMembership::class)->handle($context['membership'], $workerUser, MembershipStatus::Active);
    $sellerRole = Role::query()->withoutGlobalScope('company')
        ->where('company_id', $context['company']->getKey())
        ->where('name', 'Vendedor')
        ->firstOrFail();
    app(AssignRole::class)->handle($context['membership'], $worker, $sellerRole);

    expect(fn () => app(UpdateSalesSettings::class)->handle($worker, true))
        ->toThrow(DomainException::class, 'no puede configurar');

    $this->actingAs($workerUser)
        ->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('configuration.sales'))
        ->assertForbidden();
});

test('M owners and administrators can update the setting without destroying other settings', function () {
    $context = backdatedSalesContext();
    $salesModule = CompanyModule::query()->whereHas('module', fn ($query) => $query->where('code', ModuleCode::Sales))->sole();
    $salesModule->update(['settings' => ['existing_key' => 'preserved']]);
    app(UpdateSalesSettings::class)->handle($context['membership'], true);

    $adminUser = User::factory()->create();
    $admin = app(AddMembership::class)->handle($context['membership'], $adminUser, MembershipStatus::Active);
    $administratorRole = Role::query()->withoutGlobalScope('company')
        ->where('company_id', $context['company']->getKey())
        ->where('name', 'Administrador')
        ->firstOrFail();
    app(AssignRole::class)->handle($context['membership'], $admin, $administratorRole);
    app(UpdateSalesSettings::class)->handle($admin, false);

    expect($salesModule->refresh()->settings)->toMatchArray([
        'existing_key' => 'preserved',
        'allow_backdated_sales' => false,
    ]);

    $this->actingAs($adminUser)
        ->withSession(['current_membership_id' => $admin->getKey()])
        ->get(route('configuration.sales'))
        ->assertSuccessful()
        ->assertSee('Ventas atrasadas');
});

test('N an initial payment shares the effective date of a backdated sale', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $occurredAt = CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc();
    $sale = createBackdatedTestSale($context, $occurredAt, [
        ['payment_method' => $context['cash'], 'amount_base' => 80],
    ]);

    expect($sale->occurred_at->equalTo($occurredAt))->toBeTrue()
        ->and($sale->payments->sole()->occurred_at->equalTo($occurredAt))->toBeTrue()
        ->and($sale->payments->sole()->created_at->equalTo(now()))->toBeTrue();
});

test('O a later payment keeps its own current date', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $sale = createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc(),
    );
    $payment = app(RegisterSalePayment::class)->handle(
        $context['membership'],
        $sale,
        $context['cash'],
        80,
        now(),
    );

    expect($sale->occurred_at->equalTo($payment->occurred_at))->toBeFalse()
        ->and($payment->occurred_at->equalTo(now()))->toBeTrue();
});

test('P the sales filter assigns records near midnight to the company local day', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $previousLocalDay = createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-15 23:30:00', 'America/La_Paz')->utc(),
    );
    $currentLocalDay = createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-16 00:30:00', 'America/La_Paz')->utc(),
    );
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['owner'])
        ->test('pages::sales.index')
        ->set('dateFilter', '2026-09-15')
        ->assertSee($previousLocalDay->number)
        ->assertDontSee($currentLocalDay->number);
});

test('Q disabling the setting preserves history and blocks new explicit historical dates', function () {
    $context = backdatedSalesContext();
    enableBackdatedTestSales($context);
    $historicalSale = createBackdatedTestSale(
        $context,
        CarbonImmutable::parse('2026-09-15 16:30:00', 'America/La_Paz')->utc(),
    );
    app(UpdateSalesSettings::class)->handle($context['membership'], false);

    expect($historicalSale->refresh()->occurred_at->timezone('America/La_Paz')->toDateString())->toBe('2026-09-15')
        ->and(fn () => createBackdatedTestSale($context, now()->subDay()))
        ->toThrow(DomainException::class, 'no permite registrar ventas');

    $this->actingAs($context['owner'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('sales.index'))
        ->assertSuccessful()
        ->assertDontSee('Puedes registrar ventas de hoy o hasta 2 días calendario atrás.');
});

test('the complete local workflow lets a seller backdate only while the owner setting is enabled', function () {
    $context = backdatedSalesContext();
    $sellerUser = User::factory()->create();
    $seller = app(AddMembership::class)->handle($context['membership'], $sellerUser, MembershipStatus::Active);
    $sellerRole = Role::query()->withoutGlobalScope('company')
        ->where('company_id', $context['company']->getKey())
        ->where('name', 'Vendedor')
        ->firstOrFail();
    app(AssignRole::class)->handle($context['membership'], $seller, $sellerRole);
    app(UpdateSalesSettings::class)->handle($context['membership'], true);
    app(CurrentCompany::class)->set($seller);

    Livewire::actingAs($sellerUser)
        ->test('pages::sales.index')
        ->call('openSale')
        ->set('branchId', $context['branch']->getKey())
        ->set('warehouseId', $context['warehouse']->getKey())
        ->set('orderStatus', SaleOrderStatus::Preparing->value)
        ->set('saleLines', [['product_id' => $context['bouquet']->getKey(), 'quantity' => '1']])
        ->set('saleDateMode', 'other')
        ->set('saleOccurredAt', '2026-09-15T16:30')
        ->call('confirmSale')
        ->assertHasNoErrors();

    $sale = Sale::query()->sole();
    expect($sale->confirmed_by_membership_id)->toBe($seller->getKey())
        ->and($sale->occurred_at->timezone('America/La_Paz')->format('Y-m-d H:i'))->toBe('2026-09-15 16:30')
        ->and(StockBalance::query()->where('warehouse_id', $context['warehouse']->getKey())
            ->where('product_id', $context['supply']->getKey())->value('quantity'))->toBe('18.000000')
        ->and(app(BusinessReports::class)->summary(
            $context['membership'], 'custom', '2026-09-15', '2026-09-15',
        )['general']['sales_count'])->toBe(1);

    $this->actingAs($sellerUser)
        ->withSession(['current_membership_id' => $seller->getKey()])
        ->get(route('sales.show', ['saleId' => $sale->getKey()]))
        ->assertSuccessful()
        ->assertSee('Fecha de venta')
        ->assertSee('Registrada el')
        ->assertSee('Registrada por')
        ->assertSee('Venta registrada posteriormente');

    app(UpdateSalesSettings::class)->handle($context['membership'], false);
    app(CurrentCompany::class)->set($seller);

    Livewire::actingAs($sellerUser)
        ->test('pages::sales.index')
        ->assertDontSee('Puedes registrar ventas de hoy o hasta 2 días calendario atrás.')
        ->call('openSale')
        ->set('branchId', $context['branch']->getKey())
        ->set('warehouseId', $context['warehouse']->getKey())
        ->set('orderStatus', SaleOrderStatus::Preparing->value)
        ->set('saleLines', [['product_id' => $context['bouquet']->getKey(), 'quantity' => '1']])
        ->set('saleDateMode', 'other')
        ->set('saleOccurredAt', '2026-09-15T16:30')
        ->call('confirmSale')
        ->assertHasErrors('sale');

    expect(Sale::query()->count())->toBe(1);
});
