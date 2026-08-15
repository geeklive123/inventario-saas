<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user:User,company:Company,membership:Membership,warehouse:Warehouse,unit:Unit} */
function uiContext(): array
{
    $user = User::factory()->create();
    $currency = Currency::query()->where('code', 'BOB')->firstOrFail();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => 'Empresa UI',
        'base_currency_id' => $currency->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $user->getKey())->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory] as $code) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', $code)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey(), 'name' => 'Central']);
    $warehouse = Warehouse::factory()->forBranch($branch)->create(['name' => 'Principal']);
    $unit = Unit::factory()->create(['company_id' => $company->getKey(), 'code' => 'UND']);

    return compact('user', 'company', 'membership', 'warehouse', 'unit');
}

test('the first active membership is selected safely when the session has no company', function () {
    $context = uiContext();

    $this->actingAs($context['user'])->get(route('dashboard'))->assertSuccessful()->assertSee('Empresa UI');

    expect(session('current_membership_id'))->toBe($context['membership']->getKey());
});

test('all sprint 2.5 pages render for an authorized owner', function () {
    $context = uiContext();
    $this->actingAs($context['user'])->withSession(['current_membership_id' => $context['membership']->getKey()]);

    foreach ([
        'dashboard', 'catalog.products', 'catalog.categories', 'catalog.units', 'catalog.recipes',
        'inventory.stock', 'inventory.movements', 'configuration.branches', 'configuration.warehouses',
    ] as $routeName) {
        $this->get(route($routeName))->assertSuccessful();
    }
});

test('the product page creates a product only in the current company', function () {
    $context = uiContext();
    app(CurrentCompany::class)->set($context['membership']);
    Livewire::actingAs($context['user']);

    Livewire::test('pages::catalog.products')
        ->set('name', 'Rosa Roja')
        ->set('sku', 'ROSA-001')
        ->set('unitId', $context['unit']->getKey())
        ->set('salePriceBase', '10')
        ->set('registrationKind', 'product')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::query()->where('sku', 'ROSA-001')->firstOrFail();
    expect($product->company_id)->toBe($context['company']->getKey());
});

test('inventory UI records stock through the existing inventory action', function () {
    $context = uiContext();
    app(CurrentCompany::class)->set($context['membership']);
    Livewire::actingAs($context['user']);
    $product = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'sku' => 'TULIPAN-001',
    ]);

    Livewire::test('pages::inventory.stock')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->set('operation', 'opening')
        ->set('productId', $product->getKey())
        ->set('quantity', '60')
        ->set('unitCostBase', '7')
        ->set('reason', 'Carga inicial')
        ->call('submitOperation')
        ->assertHasNoErrors();

    $balance = StockBalance::query()->where('product_id', $product->getKey())->firstOrFail();
    expect($balance->quantity)->toBe('60.000000')
        ->and($balance->average_unit_cost_base)->toBe('7.0000');
});

test('a disabled inventory module keeps history readable and hides mutation controls', function () {
    $context = uiContext();
    app(SetModuleStatus::class)->handle(
        $context['membership'],
        Module::query()->where('code', ModuleCode::Inventory)->firstOrFail(),
        CompanyModuleStatus::Disabled,
    );

    $this->actingAs($context['user'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('inventory.stock'))
        ->assertSuccessful()
        ->assertSee('Modo histórico')
        ->assertDontSee('Cargar existencia inicial');
});

test('company switcher rejects a membership owned by another user', function () {
    $context = uiContext();
    $other = uiContext();
    app(CurrentCompany::class)->set($context['membership']);
    Livewire::actingAs($context['user']);

    Livewire::test('company-switcher')
        ->call('selectCompany', $other['membership']->getKey())
        ->assertNotFound();
});
