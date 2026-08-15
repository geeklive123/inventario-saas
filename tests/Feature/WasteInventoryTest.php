<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RecordWaste;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Inventory\ReverseStockMovement;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Enums\StockMovementType;
use App\Models\Branch;
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

test('a waste operation is immutable, traceable and reversible', function () {
    $this->seed(DatabaseSeeder::class);
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => 'Florería Mermas',
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->firstOrFail();
    app(SetModuleStatus::class)->handle(
        $membership,
        Module::query()->where('code', ModuleCode::Inventory)->firstOrFail(),
        CompanyModuleStatus::Enabled,
    );
    app(CurrentCompany::class)->set($membership);
    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);
    $product = Product::factory()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey()]);

    app(RegisterOpeningStock::class)->handle($membership, $warehouse, $product, '10', '5', 'Inicio');
    $waste = app(RecordWaste::class)->handle($membership, $warehouse, $product, '2', 'Marchita');
    $originalReason = $waste->reason;

    expect($waste->type)->toBe(StockMovementType::Waste)
        ->and($waste->created_by_membership_id)->toBe($membership->getKey())
        ->and($waste->reason)->toBe('Marchita')
        ->and($waste->lines->sole()->quantity_after)->toBe('8.000000')
        ->and(StockBalance::query()->where('product_id', $product->getKey())->value('quantity'))->toBe('8.000000');

    $reversal = app(ReverseStockMovement::class)->handle($membership, $waste, 'Corrección de merma');

    expect($waste->refresh()->reason)->toBe($originalReason)
        ->and($reversal->type)->toBe(StockMovementType::Reversal)
        ->and($reversal->reversal_of_movement_id)->toBe($waste->getKey())
        ->and(StockBalance::query()->where('product_id', $product->getKey())->value('quantity'))->toBe('10.000000');
});
