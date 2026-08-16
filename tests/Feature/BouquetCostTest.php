<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RecordManualInbound;
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
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\BouquetCostCalculator;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership, warehouse: Warehouse, unit: Unit} */
function bouquetCostContext(): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => 'Florería Costos',
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

test('the supplies screen always creates non-sellable inventory inputs', function () {
    $context = bouquetCostContext();
    Livewire::actingAs($context['user']);

    Livewire::test('pages::supplies')
        ->call('create')
        ->assertDontSee('Se puede vender')
        ->assertDontSee('Precio de venta')
        ->set('name', 'Rosa Roja')
        ->set('sku', 'ROSA-NO-VENTA')
        ->set('unitId', $context['unit']->getKey())
        ->set('estimatedCostBase', '5')
        ->call('save')
        ->assertHasNoErrors();

    $supply = Product::query()->where('sku', 'ROSA-NO-VENTA')->firstOrFail();

    expect($supply->is_sellable)->toBeFalse()
        ->and($supply->sale_price_base)->toBe('0.0000')
        ->and(Product::query()->sellable()->whereKey($supply)->exists())->toBeFalse();
});

test('bouquet cost uses current weighted average costs and recipe waste', function () {
    $context = bouquetCostContext();
    $supply = fn (string $name, string $sku): Product => Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => $name,
        'sku' => $sku,
        'is_sellable' => false,
        'fallback_unit_cost_base' => null,
    ]);
    $rose = $supply('Rosa', 'ROSA-COSTO');
    $tulip = $supply('Tulipán', 'TULIPAN-COSTO');
    $paper = $supply('Papel', 'PAPEL-COSTO');
    $ribbon = $supply('Cinta', 'CINTA-COSTO');

    app(RegisterOpeningStock::class)->handle($context['membership'], $context['warehouse'], $rose, 100, 5);
    app(RecordManualInbound::class)->handle($context['membership'], $context['warehouse'], $rose, 100, 7, 'Compra de rosas');
    app(RegisterOpeningStock::class)->handle($context['membership'], $context['warehouse'], $tulip, 100, 2);
    app(RegisterOpeningStock::class)->handle($context['membership'], $context['warehouse'], $paper, 100, 1);
    app(RegisterOpeningStock::class)->handle($context['membership'], $context['warehouse'], $ribbon, 100, '0.5');

    $bouquet = Product::factory()->composed()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => 'Ramo Amor',
        'sku' => 'RAMO-AMOR-COSTO',
        'sale_price_base' => 80,
        'is_sellable' => true,
    ]);
    app(CreateProductRecipe::class)->handle($context['membership'], $bouquet, 1, [
        ['product' => $rose, 'quantity' => 2, 'waste_percentage' => 10],
        ['product' => $tulip, 'quantity' => 3],
        ['product' => $paper, 'quantity' => 1],
        ['product' => $ribbon, 'quantity' => '1.5'],
    ]);

    $cost = app(BouquetCostCalculator::class)
        ->calculate(new Collection([$bouquet]), $context['warehouse'])[$bouquet->getKey()];
    $roseBalance = StockBalance::query()->where('warehouse_id', $context['warehouse']->getKey())
        ->where('product_id', $rose->getKey())->firstOrFail();

    expect($roseBalance->quantity)->toBe('200.000000')
        ->and($roseBalance->average_unit_cost_base)->toBe('6.0000')
        ->and($roseBalance->inventory_value_base)->toBe('1200.0000')
        ->and(StockMovement::query()->count())->toBe(5)
        ->and($cost['complete'])->toBeTrue()
        ->and($cost['uses_fallback'])->toBeFalse()
        ->and($cost['cost_base'])->toBe('20.9500');

    Livewire::actingAs($context['user'])
        ->test('pages::bouquets')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->assertSee('Costo del ramo')
        ->assertSee('20,95')
        ->assertSee('59,05');
});

test('bouquet cost does not read stock from another company', function () {
    $first = bouquetCostContext();
    $second = bouquetCostContext();
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $first['company']->getKey(),
        'unit_id' => $first['unit']->getKey(),
    ]);

    expect(fn () => app(BouquetCostCalculator::class)->calculate(
        new Collection([$bouquet]),
        $second['warehouse'],
    ))->toThrow(DomainException::class, 'same company');
});

test('editing bouquet commercial data and price preserves its active recipe', function () {
    $context = bouquetCostContext();
    $supply = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => 'Rosa',
        'sku' => 'ROSA-EDICION',
        'is_sellable' => false,
    ]);
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => 'Ramo Original',
        'sku' => 'RAMO-EDICION',
        'sale_price_base' => 80,
        'is_sellable' => true,
    ]);
    $recipe = app(CreateProductRecipe::class)->handle($context['membership'], $bouquet, 1, [
        ['product' => $supply, 'quantity' => 2],
    ]);

    Livewire::actingAs($context['user'])->test('pages::bouquets')
        ->call('edit', $bouquet->getKey())
        ->set('name', 'Ramo Renovado')
        ->set('salePriceBase', '95')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($bouquet->refresh()->name)->toBe('Ramo Renovado')
        ->and($bouquet->sale_price_base)->toBe('95.0000')
        ->and($bouquet->is_active)->toBeFalse()
        ->and($bouquet->recipes()->count())->toBe(1)
        ->and($bouquet->recipes()->where('active_slot', 1)->sole()->getKey())->toBe($recipe->getKey());
});
