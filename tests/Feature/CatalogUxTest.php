<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\InventoryBehavior;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\ProductItemType;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership, unit: Unit} */
function catalogUxContext(): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => fake()->unique()->company(),
        'base_currency_id' => Currency::query()->where('code', 'BOB')->firstOrFail()->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())
        ->where('user_id', $user->getKey())
        ->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory] as $moduleCode) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', $moduleCode)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);

    return compact('user', 'company', 'membership', 'unit');
}

test('friendly product choices are mapped to safe domain values on the server', function (
    string $registrationKind,
    ProductItemType $expectedItemType,
    InventoryBehavior $expectedInventoryBehavior,
    bool $requestedIsSellable,
    bool $expectedIsSellable,
) {
    $context = catalogUxContext();
    app(CurrentCompany::class)->set($context['membership']);
    Livewire::actingAs($context['user']);
    $sku = 'UX-'.strtoupper($registrationKind);

    Livewire::test('pages::catalog.products')
        ->set('registrationKind', $registrationKind)
        ->set('name', 'Registro '.$registrationKind)
        ->set('sku', $sku)
        ->set('unitId', $context['unit']->getKey())
        ->set('salePriceBase', '25')
        ->set('isSellable', $requestedIsSellable)
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::query()->where('sku', $sku)->firstOrFail();

    expect($product->item_type)->toBe($expectedItemType)
        ->and($product->inventory_behavior)->toBe($expectedInventoryBehavior)
        ->and($product->is_sellable)->toBe($expectedIsSellable);
})->with([
    'producto' => ['product', ProductItemType::Physical, InventoryBehavior::Self, true, true],
    'insumo no vendible' => ['supply', ProductItemType::Physical, InventoryBehavior::Self, false, false],
    'insumo manipulado como vendible permanece no vendible' => ['supply', ProductItemType::Physical, InventoryBehavior::Self, true, false],
    'producto preparado' => ['prepared', ProductItemType::Physical, InventoryBehavior::Components, true, true],
    'servicio' => ['service', ProductItemType::Service, InventoryBehavior::None, true, true],
]);

test('the product form does not expose internal inventory enum choices', function () {
    $context = catalogUxContext();
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['user'])
        ->test('pages::catalog.products')
        ->call('create')
        ->assertSee('¿Qué quieres registrar?')
        ->assertSee('Producto preparado o compuesto')
        ->assertSee('opciones avanzadas')
        ->assertDontSee('Inventario propio')
        ->assertDontSee('Consume componentes')
        ->assertDontSee('Costo fallback');
});

test('recipe changes keep history and present only the current recipe by default', function () {
    $context = catalogUxContext();
    app(CurrentCompany::class)->set($context['membership']);
    $prepared = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'inventory_behavior' => InventoryBehavior::Components,
    ]);
    $component = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
    ]);

    app(CreateProductRecipe::class)->handle(
        $context['membership'],
        $prepared,
        '1',
        [['product' => $component, 'quantity' => '1.5', 'waste_percentage' => '3']],
    );
    app(CreateProductRecipe::class)->handle(
        $context['membership'],
        $prepared,
        '2',
        [['product' => $component, 'quantity' => '2', 'waste_percentage' => '0']],
    );

    Livewire::actingAs($context['user'])
        ->withQueryParams(['producto' => $prepared->getKey()])
        ->test('pages::catalog.recipes')
        ->assertSee('Receta actual')
        ->assertSee('Ver historial de recetas')
        ->assertDontSee('Receta anterior 1')
        ->set('showHistory', true)
        ->assertSee('Receta anterior 1');
});

test('a member without catalog permission cannot create through the friendly form', function () {
    $context = catalogUxContext();
    $user = User::factory()->create();
    $membership = Membership::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $user->getKey(),
        'status' => MembershipStatus::Active,
        'is_owner' => false,
    ]);
    app(CurrentCompany::class)->set($membership);

    Livewire::actingAs($user)
        ->test('pages::catalog.products')
        ->set('registrationKind', 'product')
        ->set('name', 'No autorizado')
        ->set('sku', 'NO-AUTH')
        ->set('unitId', $context['unit']->getKey())
        ->call('save')
        ->assertForbidden();

    expect(Product::query()->withoutGlobalScope('company')->where('sku', 'NO-AUTH')->exists())->toBeFalse();
});

test('catalog screens keep products from other companies isolated', function () {
    $first = catalogUxContext();
    $second = catalogUxContext();
    Product::factory()->create([
        'company_id' => $first['company']->getKey(),
        'unit_id' => $first['unit']->getKey(),
        'name' => 'Producto visible',
    ]);
    Product::factory()->create([
        'company_id' => $second['company']->getKey(),
        'unit_id' => $second['unit']->getKey(),
        'name' => 'Producto secreto de otra empresa',
    ]);
    app(CurrentCompany::class)->set($first['membership']);

    Livewire::actingAs($first['user'])
        ->test('pages::catalog.products')
        ->assertSee('Producto visible')
        ->assertDontSee('Producto secreto de otra empresa');
});
