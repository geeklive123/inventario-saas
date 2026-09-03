<?php

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
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{user: User, company: Company, membership: Membership, warehouse: Warehouse, product: Product} */
function inventoryHistoryContext(string $name): array
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

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create([
        'company_id' => $company->getKey(),
        'code' => fake()->unique()->bothify('UND-###'),
        'symbol' => 'UND',
    ]);
    $product = Product::factory()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
        'name' => 'Rosa roja',
        'sku' => fake()->unique()->bothify('ROSA-###'),
        'is_sellable' => false,
    ]);

    return compact('user', 'company', 'membership', 'warehouse', 'product');
}

test('inventory history displays immutable snapshots and the purchase reason', function () {
    $context = inventoryHistoryContext('Historial propio');
    app(RegisterOpeningStock::class)->handle(
        $context['membership'], $context['warehouse'], $context['product'], 20, '4.20', 'Stock inicial',
    );
    $purchase = app(RecordManualInbound::class)->handle(
        $context['membership'], $context['warehouse'], $context['product'], 50, '4.40', 'Compra proveedor Mercado Floral',
    );
    $line = $purchase->lines->sole();
    $balance = StockBalance::query()->withoutGlobalScope('company')
        ->where('company_id', $context['company']->getKey())
        ->where('warehouse_id', $context['warehouse']->getKey())
        ->where('product_id', $context['product']->getKey())
        ->firstOrFail();

    expect($line->quantity)->toBe('50.000000')
        ->and($line->quantity_before)->toBe('20.000000')
        ->and($line->quantity_after)->toBe('70.000000')
        ->and($line->unit_cost_base)->toBe('4.4000')
        ->and($line->average_unit_cost_before_base)->toBe('4.2000')
        ->and($line->average_unit_cost_after_base)->toBe('4.3429')
        ->and($line->inventory_value_before_base)->toBe('84.0000')
        ->and($line->inventory_value_after_base)->toBe('304.0000')
        ->and($purchase->reason)->toBe('Compra proveedor Mercado Floral')
        ->and($balance->quantity)->toBe('70.000000')
        ->and($balance->average_unit_cost_base)->toBe('4.3429');

    app(CurrentCompany::class)->set($context['membership']);
    Livewire::actingAs($context['user'])
        ->test('pages::inventory.stock')
        ->set('warehouseId', $context['warehouse']->getKey())
        ->call('openHistory', $context['product']->getKey())
        ->assertHasNoErrors()
        ->assertSee('Historial de Rosa roja')
        ->assertSee('Compra proveedor Mercado Floral')
        ->assertSee('Costo promedio antes')
        ->assertSee('Valor después');
});

test('inventory history and its policy reject resources from another company', function () {
    $first = inventoryHistoryContext('Empresa primera');
    $second = inventoryHistoryContext('Empresa segunda');
    $firstMovement = app(RegisterOpeningStock::class)->handle(
        $first['membership'], $first['warehouse'], $first['product'], 10, 5, 'Solo primera',
    );
    app(RegisterOpeningStock::class)->handle(
        $second['membership'], $second['warehouse'], $second['product'], 12, 6, 'Solo segunda',
    );

    app(CurrentCompany::class)->set($first['membership']);

    expect(Gate::forUser($second['user'])->allows('view', $firstMovement))->toBeFalse();

    Livewire::actingAs($first['user'])
        ->test('pages::inventory.stock')
        ->set('warehouseId', $first['warehouse']->getKey())
        ->call('openHistory', $first['product']->getKey())
        ->assertSee('Solo primera')
        ->assertDontSee('Solo segunda');

    Livewire::actingAs($first['user'])
        ->test('pages::inventory.stock')
        ->set('warehouseId', $first['warehouse']->getKey())
        ->call('openHistory', $second['product']->getKey())
        ->assertNotFound();
});
