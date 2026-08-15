<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership, branch: Branch, warehouse: Warehouse} */
function salesUiContext(bool $salesEnabled = true): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => fake()->unique()->company(),
        'base_currency_id' => Currency::query()->where('code', 'BOB')->firstOrFail()->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->firstOrFail();

    app(SetModuleStatus::class)->handle(
        $membership,
        Module::query()->where('code', ModuleCode::Inventory)->firstOrFail(),
        CompanyModuleStatus::Enabled,
    );

    if ($salesEnabled) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', ModuleCode::Sales)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();

    return compact('user', 'company', 'membership', 'branch', 'warehouse');
}

test('owner sees the new sale action when sales is enabled', function () {
    $enabled = salesUiContext();

    $this->actingAs($enabled['user'])
        ->withSession(['current_membership_id' => $enabled['membership']->getKey()])
        ->get(route('sales.index'))
        ->assertSuccessful()
        ->assertSeeHtml('wire:click="openSale"');
});

test('disabled sales module renders history without the new sale action', function () {
    $disabled = salesUiContext(false);

    $this->actingAs($disabled['user'])
        ->withSession(['current_membership_id' => $disabled['membership']->getKey()])
        ->get(route('sales.index'))
        ->assertSuccessful()
        ->assertSee('Modo histórico')
        ->assertDontSeeHtml('wire:click="openSale"');
});

test('member without sales permission receives forbidden', function () {
    $context = salesUiContext();
    $user = User::factory()->create();
    $membership = Membership::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $user->getKey(),
        'status' => MembershipStatus::Active,
        'is_owner' => false,
    ]);

    $this->actingAs($user)
        ->withSession(['current_membership_id' => $membership->getKey()])
        ->get(route('sales.index'))
        ->assertForbidden();
});

test('owner cannot open a sale from another company', function () {
    $first = salesUiContext();
    $second = salesUiContext();
    $sale = Sale::factory()->create([
        'company_id' => $first['company']->getKey(),
        'branch_id' => $first['branch']->getKey(),
        'warehouse_id' => $first['warehouse']->getKey(),
        'branch_name' => $first['branch']->name,
        'warehouse_name' => $first['warehouse']->name,
        'confirmed_by_membership_id' => $first['membership']->getKey(),
        'status' => SaleStatus::Confirmed,
    ]);

    $this->actingAs($first['user'])
        ->withSession(['current_membership_id' => $first['membership']->getKey()])
        ->get(route('sales.show', ['saleId' => $sale->getKey()]))
        ->assertSuccessful()
        ->assertSee($sale->number);

    $this->actingAs($second['user'])
        ->withSession(['current_membership_id' => $second['membership']->getKey()])
        ->get(route('sales.show', ['saleId' => $sale->getKey()]))
        ->assertNotFound();
});

test('owner can confirm a sale from the Livewire form', function () {
    $context = salesUiContext();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory] as $moduleCode) {
        app(SetModuleStatus::class)->handle(
            $context['membership'],
            Module::query()->where('code', $moduleCode)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $unit = Unit::factory()->create(['company_id' => $context['company']->getKey()]);
    $supply = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $unit->getKey(),
        'name' => 'Rosa UI',
        'is_sellable' => false,
    ]);
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $unit->getKey(),
        'name' => 'Ramo UI',
        'is_sellable' => true,
        'sale_price_base' => 25,
    ]);
    app(RegisterOpeningStock::class)->handle($context['membership'], $context['warehouse'], $supply, 10, 5);
    app(CreateProductRecipe::class)->handle($context['membership'], $bouquet, 1, [['product' => $supply, 'quantity' => 2]]);
    $cash = PaymentMethod::query()->withoutGlobalScope('company')
        ->where('company_id', $context['company']->getKey())
        ->where('code', 'cash')
        ->firstOrFail();
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['user'])
        ->test('pages::sales.index')
        ->call('openSale')
        ->set('branchId', $context['branch']->getKey())
        ->set('warehouseId', $context['warehouse']->getKey())
        ->set('saleLines', [['product_id' => $bouquet->getKey(), 'quantity' => '1']])
        ->set('paymentLines', [['payment_method_id' => $cash->getKey(), 'amount_base' => '25']])
        ->call('confirmSale')
        ->assertHasNoErrors()
        ->assertRedirect(route('sales.show', ['saleId' => Sale::query()->firstOrFail()->getKey()]));
});
