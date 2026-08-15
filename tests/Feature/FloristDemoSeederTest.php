<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\BouquetCostCalculator;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FloristDemoSeeder;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('the florist demo seeder is idempotent and uses official inventory and recipe logic', function () {
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => 'Mi Florería',
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()
        ->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())
        ->where('user_id', $owner->getKey())
        ->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory] as $moduleCode) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', $moduleCode)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    app(CurrentCompany::class)->set($membership);
    $branch = Branch::factory()->create([
        'company_id' => $company->getKey(),
        'name' => 'Sucursal Central',
        'is_active' => true,
    ]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create([
        'name' => 'Almacén Principal',
        'is_active' => true,
    ]);

    $this->seed(FloristDemoSeeder::class);
    $this->seed(FloristDemoSeeder::class);

    $supplies = Product::query()->selfManaged()->whereIn('sku', [
        'ROSA-001', 'TUL-001', 'PAP-001', 'CIN-001', 'ROSA-ROS-001',
        'MAR-001', 'GIR-001', 'ROSA-BLA-001', 'LIR-001', 'EUC-001',
    ])->get();
    $bouquets = Product::query()->composed()->whereIn('sku', [
        'RAMO-AMOR-001', 'RAMO-PRIM-001', 'RAMO-ELEG-001',
    ])->get();
    $costs = app(BouquetCostCalculator::class)->calculate(new Collection($bouquets), $warehouse);

    expect($supplies)->toHaveCount(10)
        ->and($supplies->every(fn (Product $product): bool => ! $product->is_sellable))->toBeTrue()
        ->and($bouquets)->toHaveCount(3)
        ->and($bouquets->every(fn (Product $product): bool => $product->is_sellable))->toBeTrue()
        ->and(StockBalance::query()->where('warehouse_id', $warehouse->getKey())->count())->toBe(10)
        ->and(StockMovement::query()->where('warehouse_id', $warehouse->getKey())->count())->toBe(10)
        ->and(StockMovementLine::query()->count())->toBe(10)
        ->and(ProductRecipe::query()->where('active_slot', 1)->count())->toBe(3);

    expect($costs[$bouquets->firstWhere('sku', 'RAMO-AMOR-001')->getKey()]['cost_base'])->toBe('56.5000')
        ->and($costs[$bouquets->firstWhere('sku', 'RAMO-PRIM-001')->getKey()]['cost_base'])->toBe('61.0000')
        ->and($costs[$bouquets->firstWhere('sku', 'RAMO-ELEG-001')->getKey()]['cost_base'])->toBe('93.5000');
});
