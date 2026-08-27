<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Catalog\RestoreProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array<string, mixed> */
function recipeRestorationContext(string $companyName = 'Florería recetas'): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => $companyName,
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
    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);
    $rose = Product::factory()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'is_sellable' => false]);
    $paper = Product::factory()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'is_sellable' => false]);
    $bouquet = Product::factory()->composed()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'is_sellable' => true]);
    $versionOne = app(CreateProductRecipe::class)->handle($membership, $bouquet, 2, [
        ['product' => $rose, 'quantity' => 6, 'waste_percentage' => 5],
        ['product' => $paper, 'quantity' => 1, 'waste_percentage' => 0],
    ]);
    $versionTwo = app(CreateProductRecipe::class)->handle($membership, $bouquet, 1, [
        ['product' => $rose, 'quantity' => 4],
    ]);

    return compact('owner', 'company', 'membership', 'unit', 'rose', 'paper', 'bouquet', 'versionOne', 'versionTwo');
}

test('restoring a historical recipe creates a new exact version and preserves prior versions', function () {
    $context = recipeRestorationContext();
    $restored = app(RestoreProductRecipe::class)->handle($context['membership'], $context['versionOne']);
    $items = $restored->items->keyBy('component_product_id');

    expect($restored->version)->toBe(3)
        ->and($restored->yield_quantity)->toBe('2.000000')
        ->and($items[$context['rose']->getKey()]->quantity)->toBe('6.000000')
        ->and($items[$context['rose']->getKey()]->waste_percentage)->toBe('5.000000')
        ->and($items[$context['paper']->getKey()]->quantity)->toBe('1.000000')
        ->and($context['versionOne']->refresh()->active_slot)->toBeNull()
        ->and($context['versionTwo']->refresh()->active_slot)->toBeNull()
        ->and($restored->active_slot)->toBe(1);
});

test('restoring a recipe does not alter historical sale snapshots', function () {
    $context = recipeRestorationContext();
    $sale = Sale::factory()->create([
        'company_id' => $context['company']->getKey(),
        'confirmed_by_membership_id' => $context['membership']->getKey(),
    ]);
    $saleItem = SaleItem::factory()->create([
        'company_id' => $context['company']->getKey(),
        'sale_id' => $sale->getKey(),
        'product_id' => $context['bouquet']->getKey(),
        'product_recipe_id' => $context['versionOne']->getKey(),
        'recipe_version' => 1,
        'total_cost_base' => 25,
    ]);

    app(RestoreProductRecipe::class)->handle($context['membership'], $context['versionOne']);

    expect($saleItem->refresh()->product_recipe_id)->toBe($context['versionOne']->getKey())
        ->and($saleItem->recipe_version)->toBe(1)
        ->and($saleItem->total_cost_base)->toBe('25.0000');
});

test('recipe restoration enforces company isolation and recipe permission', function () {
    $first = recipeRestorationContext('Primera empresa');
    $second = recipeRestorationContext('Segunda empresa');
    $worker = Membership::factory()->create([
        'company_id' => $first['company']->getKey(),
        'user_id' => User::factory()->create()->getKey(),
        'is_owner' => false,
    ]);

    expect(fn () => app(RestoreProductRecipe::class)->handle($second['membership'], $first['versionOne']))
        ->toThrow(DomainException::class, 'no puede restaurar')
        ->and(fn () => app(RestoreProductRecipe::class)->handle($worker, $first['versionOne']))
        ->toThrow(DomainException::class, 'no puede restaurar');
});
