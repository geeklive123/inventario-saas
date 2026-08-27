<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Inventory\ReverseStockMovement;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('opening stock selector excludes supplies with historical opening even when stock returned to zero', function () {
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => fake()->company(),
        'base_currency_id' => Currency::query()->where('code', 'BOB')->firstOrFail()->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory] as $moduleCode) {
        app(SetModuleStatus::class)->handle($membership, Module::query()->where('code', $moduleCode)->firstOrFail(), CompanyModuleStatus::Enabled);
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);
    $opened = Product::factory()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Rosa ya abierta', 'is_sellable' => false]);
    $pending = Product::factory()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Tulipán pendiente', 'is_sellable' => false]);
    $opening = app(RegisterOpeningStock::class)->handle($membership, $warehouse, $opened, 10, 5);
    app(ReverseStockMovement::class)->handle($membership, $opening, 'Corrección');

    app(CurrentCompany::class)->set($membership);
    session()->put('current_warehouse_id', $warehouse->getKey());
    $component = Livewire::actingAs($user)->test('pages::inventory.stock')
        ->set('warehouseId', $warehouse->getKey())
        ->call('openOperation', 'opening');
    $availableIds = $component->instance()->products->modelKeys();

    expect($availableIds)->not->toContain($opened->getKey())
        ->and($availableIds)->toContain($pending->getKey());

    expect(fn () => app(RegisterOpeningStock::class)->handle($membership, $warehouse, $opened, 1, 5))
        ->toThrow(DomainException::class, 'ya tiene una existencia inicial');

    app(RegisterOpeningStock::class)->handle($membership, $warehouse, $pending, 10, 7);

    $this->actingAs($user)
        ->withSession(['current_membership_id' => $membership->getKey(), 'current_warehouse_id' => $warehouse->getKey()])
        ->get(route('inventory.stock').'?operacion=opening')
        ->assertSuccessful()
        ->assertSee('Todos los insumos ya tienen una existencia inicial registrada.')
        ->assertSee('Para agregar nuevas unidades utiliza Registrar compra.');
});
