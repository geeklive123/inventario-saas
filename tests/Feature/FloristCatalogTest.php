<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\InventoryBehavior;
use App\Enums\ModuleCode;
use App\Enums\ProductItemType;
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

test('the florist screens persist supplies and bouquets using the existing product domain', function () {
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => 'Florería Jardín',
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->firstOrFail();
    app(SetModuleStatus::class)->handle(
        $membership,
        Module::query()->where('code', ModuleCode::Catalog)->firstOrFail(),
        CompanyModuleStatus::Enabled,
    );
    app(CurrentCompany::class)->set($membership);
    $unit = Unit::factory()->create(['company_id' => $company->getKey(), 'name' => 'Unidad', 'symbol' => 'u']);
    Livewire::actingAs($owner);

    Livewire::test('pages::supplies')
        ->set('name', 'Rosa Roja')->set('sku', 'ROSA-001')->set('unitId', $unit->getKey())
        ->set('estimatedCostBase', '4.50')->call('save')->assertHasNoErrors();
    Livewire::test('pages::bouquets')
        ->set('name', 'Ramo Amor')->set('sku', 'RAMO-001')->set('unitId', $unit->getKey())
        ->set('salePriceBase', '120')->call('save')->assertHasNoErrors();

    $supply = Product::query()->where('sku', 'ROSA-001')->firstOrFail();
    $bouquet = Product::query()->where('sku', 'RAMO-001')->firstOrFail();

    expect($supply->item_type)->toBe(ProductItemType::Physical)
        ->and($supply->inventory_behavior)->toBe(InventoryBehavior::Self)
        ->and($supply->is_sellable)->toBeFalse()
        ->and($bouquet->item_type)->toBe(ProductItemType::Physical)
        ->and($bouquet->inventory_behavior)->toBe(InventoryBehavior::Components)
        ->and($bouquet->is_sellable)->toBeTrue()
        ->and($supply->company_id)->toBe($company->getKey())
        ->and($bouquet->company_id)->toBe($company->getKey());
});
