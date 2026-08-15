<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Enums\StockMovementType;
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
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership, warehouse: Warehouse, unit: Unit} */
function inventoryCostUxContext(): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => 'Florería UX Costos',
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()
        ->withoutGlobalScope('company')
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
    $unit = Unit::factory()->create([
        'company_id' => $company->getKey(),
        'code' => 'UND',
        'name' => 'Unidad',
        'symbol' => 'u',
    ]);

    return compact('user', 'company', 'membership', 'warehouse', 'unit');
}

test('reference cost never changes real inventory while purchases update weighted average cost', function () {
    $context = inventoryCostUxContext();
    Livewire::actingAs($context['user']);

    Livewire::test('pages::supplies')
        ->set('name', 'Rosa Roja')
        ->set('sku', 'ROSA-UX-001')
        ->set('description', 'Rosa para ramos')
        ->set('unitId', $context['unit']->getKey())
        ->set('estimatedCostBase', '5')
        ->call('save')
        ->assertHasNoErrors();

    $rose = Product::query()->where('sku', 'ROSA-UX-001')->firstOrFail();

    Livewire::test('pages::inventory.stock')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->call('openOperation', 'opening', $rose->getKey())
        ->assertSet('unitCostBase', '5.0000')
        ->set('quantity', '100')
        ->set('reason', 'Inventario inicial')
        ->call('submitOperation')
        ->assertHasNoErrors();

    $openingBalance = StockBalance::query()->where('product_id', $rose->getKey())->firstOrFail();

    expect($openingBalance->quantity)->toBe('100.000000')
        ->and($openingBalance->average_unit_cost_base)->toBe('5.0000')
        ->and($openingBalance->inventory_value_base)->toBe('500.0000')
        ->and($openingBalance->last_inbound_unit_cost_base)->toBe('5.0000')
        ->and(StockMovement::query()->count())->toBe(1);

    Livewire::test('pages::supplies')
        ->call('edit', $rose->getKey())
        ->set('estimatedCostBase', '1')
        ->call('save')
        ->assertHasNoErrors();

    $referenceOnlyBalance = $openingBalance->refresh();

    expect($rose->refresh()->fallback_unit_cost_base)->toBe('1.0000')
        ->and($referenceOnlyBalance->quantity)->toBe('100.000000')
        ->and($referenceOnlyBalance->average_unit_cost_base)->toBe('5.0000')
        ->and($referenceOnlyBalance->inventory_value_base)->toBe('500.0000')
        ->and($referenceOnlyBalance->last_inbound_unit_cost_base)->toBe('5.0000')
        ->and(StockMovement::query()->count())->toBe(1);

    Livewire::test('pages::inventory.stock')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->call('openOperation', 'inbound', $rose->getKey())
        ->set('quantity', '20')
        ->set('unitCostBase', '1')
        ->assertSee('20 × Bs 1,00 = Bs 20,00')
        ->assertSee('Bs 4,33')
        ->set('reason', 'Compra de rosas')
        ->call('submitOperation')
        ->assertHasNoErrors()
        ->assertSee('120 u')
        ->assertSee('Bs 4,33')
        ->assertSee('Bs 520,00')
        ->assertSee('Bs 1,00');

    $purchasedBalance = $openingBalance->refresh();

    expect($purchasedBalance->quantity)->toBe('120.000000')
        ->and($purchasedBalance->average_unit_cost_base)->toBe('4.3333')
        ->and($purchasedBalance->inventory_value_base)->toBe('520.0000')
        ->and($purchasedBalance->last_inbound_unit_cost_base)->toBe('1.0000');

    Livewire::test('pages::inventory.stock')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->call('openOperation', 'outbound', $rose->getKey())
        ->set('quantity', '10')
        ->set('reason', 'Uso interno')
        ->call('submitOperation')
        ->assertHasNoErrors();

    $outboundBalance = $openingBalance->refresh();

    expect($outboundBalance->quantity)->toBe('110.000000')
        ->and($outboundBalance->average_unit_cost_base)->toBe('4.3333')
        ->and($outboundBalance->inventory_value_base)->toBe('476.6670')
        ->and($outboundBalance->last_inbound_unit_cost_base)->toBe('1.0000')
        ->and(StockMovement::query()->count())->toBe(3);
});

test('physical stock correction records only the difference through the inventory action', function () {
    $context = inventoryCostUxContext();
    $product = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'is_sellable' => false,
    ]);
    Livewire::actingAs($context['user']);

    Livewire::test('pages::inventory.stock')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->call('openOperation', 'opening', $product->getKey())
        ->set('quantity', '100')
        ->set('unitCostBase', '5')
        ->call('submitOperation')
        ->assertHasNoErrors();

    Livewire::test('pages::inventory.stock')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->call('openOperation', 'adjustment', $product->getKey())
        ->set('quantity', '97')
        ->assertSee('Se registrará una diferencia de -3')
        ->set('reason', 'Conteo físico')
        ->call('submitOperation')
        ->assertHasNoErrors();

    $balance = StockBalance::query()->where('product_id', $product->getKey())->firstOrFail();
    $adjustment = StockMovement::query()->where('type', StockMovementType::AdjustmentOut)->latest('id')->firstOrFail();

    expect($balance->quantity)->toBe('97.000000')
        ->and($balance->average_unit_cost_base)->toBe('5.0000')
        ->and($balance->inventory_value_base)->toBe('485.0000')
        ->and($adjustment->lines->firstOrFail()->quantity)->toBe('-3.000000');
});

test('inventory screens explain costs with friendly labels and localized money', function () {
    $context = inventoryCostUxContext();
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['user'])
        ->test('pages::supplies')
        ->call('create')
        ->assertSee('Costo de referencia')
        ->assertSee('El costo real del inventario se calcula con las compras o entradas registradas.')
        ->assertDontSee('Costo estimado');

    Livewire::actingAs($context['user'])
        ->test('pages::inventory.stock')
        ->assertSee('Inventario')
        ->assertSee('Cargar existencia inicial')
        ->assertSee('Registrar compra / entrada')
        ->assertSee('Precio promedio de las unidades que actualmente tienes en inventario.')
        ->assertSee('Existencia actual × costo promedio.')
        ->assertSee('Precio unitario registrado en la última compra o entrada con costo.')
        ->assertDontSee('Registrar stock inicial');
});
