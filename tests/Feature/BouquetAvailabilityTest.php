<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\BouquetAvailabilityCalculator;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership, warehouse: Warehouse, unit: Unit} */
function bouquetAvailabilityContext(string $name = 'Florería disponibilidad'): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => $name,
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
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

    app(CurrentCompany::class)->set($membership);
    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create(['company_id' => $company->getKey(), 'name' => 'Unidad', 'symbol' => 'u']);

    return compact('user', 'company', 'membership', 'warehouse', 'unit');
}

/** @param array{company: Company, unit: Unit} $context */
function availabilitySupply(array $context, string $name, string $sku): Product
{
    return Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => $name,
        'sku' => $sku,
        'is_sellable' => false,
        'fallback_unit_cost_base' => null,
    ]);
}

/** @param array{company: Company, unit: Unit} $context */
function availabilityBouquet(array $context, string $name = 'Ramo disponible'): Product
{
    return Product::factory()->composed()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => $name,
        'sku' => fake()->unique()->bothify('RAM-DISP-####'),
        'is_sellable' => true,
    ]);
}

/**
 * @param  array{membership: Membership, warehouse: Warehouse}  $context
 * @param  list<array{product: Product, quantity: int|float|string, waste_percentage?: int|float|string}>  $components
 */
function recipeForAvailability(array $context, Product $bouquet, array $components, int|float|string $yield = 1): void
{
    app(CreateProductRecipe::class)->handle($context['membership'], $bouquet, $yield, $components);
}

/** @param array{membership: Membership, warehouse: Warehouse} $context */
function openingForAvailability(array $context, Product $product, int|float|string $quantity): void
{
    app(RegisterOpeningStock::class)->handle(
        $context['membership'],
        $context['warehouse'],
        $product,
        $quantity,
        1,
    );
}

/**
 * @param  array{warehouse: Warehouse}  $context
 * @return array{has_recipe: bool, available: bool, possible_quantity: numeric-string, components: list<array<string, mixed>>}
 */
function calculateAvailability(array $context, Product $bouquet): array
{
    return app(BouquetAvailabilityCalculator::class)
        ->calculate(new Collection([$bouquet]), $context['warehouse'])[$bouquet->getKey()];
}

test('availability uses the limiting recipe ingredient', function () {
    $context = bouquetAvailabilityContext();
    $rose = availabilitySupply($context, 'Rosa roja', 'ROSA-DISP');
    $paper = availabilitySupply($context, 'Papel', 'PAPEL-DISP');
    $ribbon = availabilitySupply($context, 'Cinta', 'CINTA-DISP');
    $bouquet = availabilityBouquet($context);
    recipeForAvailability($context, $bouquet, [
        ['product' => $rose, 'quantity' => 10],
        ['product' => $paper, 'quantity' => 2],
        ['product' => $ribbon, 'quantity' => 1],
    ]);
    openingForAvailability($context, $rose, 25);
    openingForAvailability($context, $paper, 10);
    openingForAvailability($context, $ribbon, 10);

    $availability = calculateAvailability($context, $bouquet);
    $components = collect($availability['components'])->keyBy('product_id');

    expect($availability['has_recipe'])->toBeTrue()
        ->and($availability['available'])->toBeTrue()
        ->and($availability['possible_quantity'])->toBe('2')
        ->and($components[$rose->getKey()]['possible_quantity'])->toBe('2')
        ->and($components[$paper->getKey()]['possible_quantity'])->toBe('5')
        ->and($components[$ribbon->getKey()]['possible_quantity'])->toBe('10');
});

test('stock zero makes the bouquet unavailable', function () {
    $context = bouquetAvailabilityContext();
    $rose = availabilitySupply($context, 'Rosa', 'ROSA-CERO');
    $bouquet = availabilityBouquet($context);
    recipeForAvailability($context, $bouquet, [['product' => $rose, 'quantity' => 2]]);

    $availability = calculateAvailability($context, $bouquet);
    $component = $availability['components'][0];

    expect($availability['available'])->toBeFalse()
        ->and($availability['possible_quantity'])->toBe('0')
        ->and($component['available_quantity'])->toBe('0.000000')
        ->and($component['missing_quantity'])->toBe('2.000000')
        ->and($component['sufficient'])->toBeFalse();
});

test('insufficient stock reports required available and missing quantities', function () {
    $context = bouquetAvailabilityContext();
    $rose = availabilitySupply($context, 'Rosa roja', 'ROSA-FALTA');
    $bouquet = availabilityBouquet($context);
    recipeForAvailability($context, $bouquet, [['product' => $rose, 'quantity' => 10]]);
    openingForAvailability($context, $rose, 6);

    $component = calculateAvailability($context, $bouquet)['components'][0];

    expect($component['required_quantity'])->toBe('10.000000')
        ->and($component['available_quantity'])->toBe('6.000000')
        ->and($component['missing_quantity'])->toBe('4.000000')
        ->and($component['possible_quantity'])->toBe('0')
        ->and($component['sufficient'])->toBeFalse();
});

test('recipe yield reduces the quantity required per bouquet', function () {
    $context = bouquetAvailabilityContext();
    $rose = availabilitySupply($context, 'Rosa', 'ROSA-YIELD');
    $bouquet = availabilityBouquet($context);
    recipeForAvailability($context, $bouquet, [['product' => $rose, 'quantity' => 10]], yield: 2);
    openingForAvailability($context, $rose, 20);

    $availability = calculateAvailability($context, $bouquet);

    expect($availability['possible_quantity'])->toBe('4')
        ->and($availability['components'][0]['required_quantity'])->toBe('5.000000');
});

test('recipe waste increases the quantity required per bouquet', function () {
    $context = bouquetAvailabilityContext();
    $rose = availabilitySupply($context, 'Rosa', 'ROSA-MERMA');
    $bouquet = availabilityBouquet($context);
    recipeForAvailability($context, $bouquet, [[
        'product' => $rose,
        'quantity' => 10,
        'waste_percentage' => 10,
    ]]);
    openingForAvailability($context, $rose, 25);

    $availability = calculateAvailability($context, $bouquet);

    expect($availability['possible_quantity'])->toBe('2')
        ->and($availability['components'][0]['required_quantity'])->toBe('11.000000');
});

test('availability is calculated independently for each warehouse', function () {
    $context = bouquetAvailabilityContext();
    $secondWarehouse = Warehouse::factory()->create([
        'company_id' => $context['company']->getKey(),
        'branch_id' => $context['warehouse']->branch_id,
    ]);
    $rose = availabilitySupply($context, 'Rosa', 'ROSA-ALMACEN');
    $bouquet = availabilityBouquet($context);
    recipeForAvailability($context, $bouquet, [['product' => $rose, 'quantity' => 10]]);
    openingForAvailability($context, $rose, 25);
    app(RegisterOpeningStock::class)->handle($context['membership'], $secondWarehouse, $rose, 6, 1);

    $first = calculateAvailability($context, $bouquet);
    $second = app(BouquetAvailabilityCalculator::class)
        ->calculate(new Collection([$bouquet]), $secondWarehouse)[$bouquet->getKey()];

    expect($first['possible_quantity'])->toBe('2')
        ->and($second['possible_quantity'])->toBe('0')
        ->and($second['components'][0]['available_quantity'])->toBe('6.000000');
});

test('bouquets and warehouses from different companies are rejected', function () {
    $first = bouquetAvailabilityContext('Primera florería');
    $bouquet = availabilityBouquet($first);
    $second = bouquetAvailabilityContext('Segunda florería');

    expect(fn () => app(BouquetAvailabilityCalculator::class)->calculate(
        new Collection([$bouquet]),
        $second['warehouse'],
    ))->toThrow(DomainException::class, 'same company');
});

test('a generic ingredient does not use stock from named variants', function () {
    $context = bouquetAvailabilityContext();
    $genericRose = availabilitySupply($context, 'Rosa', 'ROSA-GENERICA');
    $redRose = availabilitySupply($context, 'Rosa roja', 'ROSA-ROJA-VARIANTE');
    $bouquet = availabilityBouquet($context);
    recipeForAvailability($context, $bouquet, [['product' => $genericRose, 'quantity' => 2]]);
    openingForAvailability($context, $redRose, 100);

    $availability = calculateAvailability($context, $bouquet);

    expect($availability['possible_quantity'])->toBe('0')
        ->and($availability['components'][0]['product_id'])->toBe($genericRose->getKey())
        ->and($availability['components'][0]['available_quantity'])->toBe('0.000000');
});

test('a bouquet without an active recipe has no availability', function () {
    $context = bouquetAvailabilityContext();
    $bouquet = availabilityBouquet($context);

    expect(calculateAvailability($context, $bouquet))->toMatchArray([
        'has_recipe' => false,
        'available' => false,
        'possible_quantity' => '0',
        'components' => [],
    ]);
});

test('the bouquets screen shows availability separately from cost', function () {
    $context = bouquetAvailabilityContext();
    $rose = availabilitySupply($context, 'Rosa roja', 'ROSA-UI-DISP');
    $bouquet = availabilityBouquet($context, 'Ramo UI disponibilidad');
    recipeForAvailability($context, $bouquet, [['product' => $rose, 'quantity' => 10]]);
    openingForAvailability($context, $rose, 6);
    session()->put('current_membership_id', $context['membership']->getKey());

    Livewire::actingAs($context['user'])
        ->test('pages::bouquets')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->assertSee('Disponibilidad')
        ->assertSee('Stock insuficiente')
        ->assertSee('No puedes preparar este ramo')
        ->assertSee('Rosa roja')
        ->assertSee('Requiere 10 / disponible 6 / faltan 4')
        ->assertSee('Costo del ramo');
});
