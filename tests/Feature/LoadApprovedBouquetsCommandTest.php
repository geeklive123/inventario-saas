<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\InventoryBehavior;
use App\Enums\ModuleCode;
use App\Enums\ProductItemType;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{membership: Membership, unit: Unit, category: Category} */
function approvedBouquetContext(array $excludedSupplySkus = []): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => 'Florería aprobada',
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    expect($company->getKey())->toBe(1);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->firstOrFail();
    app(SetModuleStatus::class)->handle(
        $membership,
        Module::query()->where('code', ModuleCode::Catalog)->firstOrFail(),
        CompanyModuleStatus::Enabled,
    );
    $unit = Unit::factory()->create([
        'company_id' => $company->getKey(), 'code' => 'UND', 'name' => 'Unidad', 'symbol' => 'u', 'decimal_places' => 0,
    ]);
    $category = Category::factory()->create([
        'company_id' => $company->getKey(), 'code' => 'RAMOS', 'name' => 'Ramos',
    ]);

    foreach (approvedSupplySkus() as $sku) {
        if (in_array($sku, $excludedSupplySkus, true)) {
            continue;
        }

        Product::factory()->create([
            'company_id' => $company->getKey(),
            'unit_id' => $unit->getKey(),
            'sku' => $sku,
            'name' => "Insumo {$sku}",
            'item_type' => ProductItemType::Physical,
            'inventory_behavior' => InventoryBehavior::Self,
            'is_sellable' => false,
            'sale_price_base' => 0,
            'is_active' => true,
        ]);
    }

    return compact('membership', 'unit', 'category');
}

/** @return list<string> */
function approvedSupplySkus(): array
{
    return ['INS-0006', 'INS-0007', 'INS-0013', 'INS-0015', 'INS-0016', 'INS-0017', 'INS-0019', 'INS-0028', 'INS-0029', 'INS-0037', 'INS-0042', 'INS-0044', 'INS-0046', 'INS-0048', 'INS-0056', 'INS-0064', 'INS-0078', 'INS-0079', 'INS-0080', 'INS-0081', 'INS-0082', 'INS-0083'];
}

/** @return array<string, array{name: string, price: string, components: array<string, string>}> */
function expectedApprovedBouquets(): array
{
    return [
        'RAM-0002' => ['name' => 'Rayo de Sol', 'price' => '70.0000', 'components' => ['INS-0044' => '2.000000', 'INS-0029' => '5.000000', 'INS-0019' => '4.000000', 'INS-0015' => '1.000000', 'INS-0081' => '1.000000', 'INS-0079' => '1.000000', 'INS-0078' => '1.000000', 'INS-0082' => '1.000000']],
        'RAM-0003' => ['name' => 'Encanto Amarillo', 'price' => '65.0000', 'components' => ['INS-0056' => '1.000000', 'INS-0042' => '1.000000', 'INS-0029' => '3.000000', 'INS-0046' => '1.000000', 'INS-0016' => '4.000000', 'INS-0017' => '5.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0078' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0004' => ['name' => 'Trío Radiante', 'price' => '100.0000', 'components' => ['INS-0044' => '3.000000', 'INS-0029' => '4.000000', 'INS-0046' => '2.000000', 'INS-0017' => '2.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0078' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0005' => ['name' => 'Brillo Salvaje', 'price' => '165.0000', 'components' => ['INS-0044' => '4.000000', 'INS-0029' => '7.000000', 'INS-0056' => '12.000000', 'INS-0028' => '3.000000', 'INS-0017' => '2.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0078' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0006' => ['name' => 'Dulce Ilusión', 'price' => '165.0000', 'components' => ['INS-0056' => '15.000000', 'INS-0029' => '6.000000', 'INS-0016' => '1.000000', 'INS-0017' => '5.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0078' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0007' => ['name' => 'Detalle de Luz', 'price' => '135.0000', 'components' => ['INS-0056' => '15.000000', 'INS-0029' => '6.000000', 'INS-0006' => '1.000000', 'INS-0028' => '5.000000', 'INS-0083' => '1.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0078' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0008' => ['name' => 'Pétalos del Día', 'price' => '150.0000', 'components' => ['INS-0056' => '20.000000', 'INS-0007' => '1.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0078' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0009' => ['name' => 'Pasión Amarilla', 'price' => '100.0000', 'components' => ['INS-0037' => '20.000000', 'INS-0029' => '6.000000', 'INS-0017' => '3.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0078' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0010' => ['name' => 'Rayos de Amor', 'price' => '155.0000', 'components' => ['INS-0056' => '8.000000', 'INS-0044' => '2.000000', 'INS-0029' => '4.000000', 'INS-0046' => '2.000000', 'INS-0016' => '1.000000', 'INS-0019' => '5.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0011' => ['name' => 'Brillo Suave', 'price' => '120.0000', 'components' => ['INS-0056' => '8.000000', 'INS-0029' => '5.000000', 'INS-0064' => '4.000000', 'INS-0016' => '2.000000', 'INS-0019' => '5.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0012' => ['name' => 'Luz y Amor', 'price' => '165.0000', 'components' => ['INS-0056' => '5.000000', 'INS-0048' => '5.000000', 'INS-0029' => '5.000000', 'INS-0064' => '4.000000', 'INS-0017' => '5.000000', 'INS-0016' => '3.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0013' => ['name' => 'Destello Dorado', 'price' => '55.0000', 'components' => ['INS-0044' => '1.000000', 'INS-0029' => '4.000000', 'INS-0064' => '3.000000', 'INS-0028' => '2.000000', 'INS-0019' => '4.000000', 'INS-0080' => '1.000000', 'INS-0079' => '1.000000', 'INS-0082' => '1.000000', 'INS-0081' => '1.000000', 'INS-0015' => '1.000000']],
        'RAM-0014' => ['name' => 'Tormenta de Rosas', 'price' => '510.0000', 'components' => ['INS-0056' => '70.000000', 'INS-0017' => '10.000000', 'INS-0029' => '40.000000', 'INS-0078' => '1.000000', 'INS-0015' => '1.000000', 'INS-0081' => '1.000000', 'INS-0079' => '1.000000', 'INS-0080' => '1.000000', 'INS-0082' => '1.000000']],
        'RAM-0015' => ['name' => '100 Razones para Amar', 'price' => '590.0000', 'components' => ['INS-0056' => '100.000000', 'INS-0017' => '10.000000', 'INS-0029' => '20.000000', 'INS-0078' => '1.000000', 'INS-0015' => '1.000000', 'INS-0081' => '1.000000', 'INS-0079' => '1.000000', 'INS-0080' => '1.000000', 'INS-0082' => '1.000000']],
        'RAM-0016' => ['name' => 'Corazón Profundo', 'price' => '540.0000', 'components' => ['INS-0056' => '80.000000', 'INS-0017' => '10.000000', 'INS-0029' => '10.000000', 'INS-0078' => '1.000000', 'INS-0015' => '1.000000', 'INS-0081' => '1.000000', 'INS-0079' => '1.000000', 'INS-0080' => '1.000000', 'INS-0082' => '1.000000']],
        'RAM-0017' => ['name' => 'Ramo Majestad', 'price' => '590.0000', 'components' => ['INS-0056' => '50.000000', 'INS-0017' => '8.000000', 'INS-0013' => '5.000000', 'INS-0078' => '1.000000', 'INS-0015' => '2.000000', 'INS-0081' => '1.000000', 'INS-0079' => '1.000000', 'INS-0080' => '1.000000', 'INS-0082' => '1.000000']],
    ];
}

test('command creates the sixteen approved bouquets with exact version one recipes and no inventory records', function () {
    approvedBouquetContext();

    $this->artisan('app:load-approved-bouquets')
        ->expectsOutputToContain('Resumen: creados=16, omitidos=0, errores=0.')
        ->assertSuccessful();

    $bouquets = Product::query()->withoutGlobalScope('company')
        ->with(['recipes.items.componentProduct'])
        ->where('company_id', 1)
        ->whereIn('sku', array_keys(expectedApprovedBouquets()))
        ->get()
        ->keyBy('sku');

    expect($bouquets)->toHaveCount(16)
        ->and(ProductRecipe::query()->withoutGlobalScope('company')->where('company_id', 1)->count())->toBe(16)
        ->and(StockMovement::query()->withoutGlobalScope('company')->where('company_id', 1)->count())->toBe(0)
        ->and(StockBalance::query()->withoutGlobalScope('company')->where('company_id', 1)->count())->toBe(0);

    foreach (expectedApprovedBouquets() as $sku => $expected) {
        $bouquet = $bouquets->get($sku);
        $recipe = $bouquet?->recipes->sole();
        $actualComponents = $recipe?->items->mapWithKeys(
            fn ($item): array => [$item->componentProduct->sku => $item->quantity],
        )->all();

        expect($bouquet)->not->toBeNull()
            ->and($bouquet->name)->toBe($expected['name'])
            ->and($bouquet->sale_price_base)->toBe($expected['price'])
            ->and($bouquet->item_type)->toBe(ProductItemType::Physical)
            ->and($bouquet->inventory_behavior)->toBe(InventoryBehavior::Components)
            ->and($bouquet->is_sellable)->toBeTrue()
            ->and($recipe?->version)->toBe(1)
            ->and($recipe?->active_slot)->toBe(1)
            ->and($recipe?->yield_quantity)->toBe('1.000000')
            ->and($actualComponents)->toBe($expected['components'])
            ->and($recipe?->items->every(fn ($item): bool => $item->waste_percentage === '0.000000'))->toBeTrue();
    }
});

test('second command execution is idempotent and creates no recipe versions', function () {
    approvedBouquetContext();

    $this->artisan('app:load-approved-bouquets')->assertSuccessful();
    $this->artisan('app:load-approved-bouquets')
        ->expectsOutputToContain('Resumen: creados=0, omitidos=16, errores=0.')
        ->assertSuccessful();

    expect(Product::query()->withoutGlobalScope('company')->where('company_id', 1)->whereIn('sku', array_keys(expectedApprovedBouquets()))->count())->toBe(16)
        ->and(ProductRecipe::query()->withoutGlobalScope('company')->where('company_id', 1)->count())->toBe(16);
});

test('existing bouquet is omitted without modification', function () {
    $context = approvedBouquetContext();
    $existing = Product::factory()->composed()->create([
        'company_id' => 1,
        'unit_id' => $context['unit']->getKey(),
        'category_id' => $context['category']->getKey(),
        'sku' => 'RAM-0002',
        'name' => 'Nombre conservado',
        'sale_price_base' => '999',
    ]);

    $this->artisan('app:load-approved-bouquets')
        ->expectsOutputToContain('Resumen: creados=15, omitidos=1, errores=0.')
        ->assertSuccessful();

    expect($existing->refresh()->name)->toBe('Nombre conservado')
        ->and($existing->sale_price_base)->toBe('999.0000')
        ->and($existing->recipes()->withoutGlobalScope('company')->count())->toBe(0);
});

test('missing company supply prevents only affected bouquet even when another company has the sku', function () {
    approvedBouquetContext(['INS-0042']);
    $foreignOwner = User::factory()->create();
    $foreignCompany = app(CreateCompany::class)->handle($foreignOwner, [
        'name' => 'Otra florería',
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz', 'locale' => 'es',
    ]);
    $foreignUnit = Unit::factory()->create(['company_id' => $foreignCompany->getKey()]);
    Product::factory()->create(['company_id' => $foreignCompany->getKey(), 'unit_id' => $foreignUnit->getKey(), 'sku' => 'INS-0042']);
    $foreignBouquet = Product::factory()->composed()->create([
        'company_id' => $foreignCompany->getKey(), 'unit_id' => $foreignUnit->getKey(), 'sku' => 'RAM-0002', 'name' => 'Ramo extranjero',
    ]);

    $this->artisan('app:load-approved-bouquets')
        ->expectsOutputToContain('ERROR RAM-0003 — faltan insumos en company_id=1: INS-0042.')
        ->expectsOutputToContain('Resumen: creados=15, omitidos=0, errores=1.')
        ->assertFailed();

    expect(Product::query()->withoutGlobalScope('company')->where('company_id', 1)->where('sku', 'RAM-0003')->exists())->toBeFalse()
        ->and(Product::query()->withoutGlobalScope('company')->where('company_id', 1)->whereIn('sku', array_keys(expectedApprovedBouquets()))->count())->toBe(15)
        ->and($foreignBouquet->refresh()->name)->toBe('Ramo extranjero')
        ->and(ProductRecipe::query()->withoutGlobalScope('company')->where('company_id', $foreignCompany->getKey())->count())->toBe(0)
        ->and(StockMovement::query()->withoutGlobalScope('company')->count())->toBe(0);
});
