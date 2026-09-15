<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Inventory\ReverseStockMovement;
use App\Actions\Memberships\AddMembership;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Roles\AssignRole;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\RegularizeSaleInventory;
use App\Actions\Sales\VoidSale;
use App\Enums\CompanyModuleStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\SaleInventoryPendingStatus;
use App\Enums\SaleInventoryStatus;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use DomainException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{owner: User, company: Company, membership: Membership, branch: Branch, warehouse: Warehouse, unit: Unit} */
function regularizationContext(): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => fake()->unique()->company(),
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
        'allow_negative_stock' => true,
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory, ModuleCode::Sales] as $code) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', $code)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create([
        'company_id' => $company->getKey(),
        'code' => fake()->unique()->bothify('UND-###'),
        'symbol' => 'UND',
        'decimal_places' => 0,
    ]);

    return compact('owner', 'company', 'membership', 'branch', 'warehouse', 'unit');
}

function regularizationSupply(
    array $context,
    string $name,
    string $sku,
    ?string $stock = null,
    ?string $cost = null,
): Product {
    $product = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => $name,
        'sku' => $sku,
        'is_sellable' => false,
        'sale_price_base' => 0,
        'fallback_unit_cost_base' => $cost,
    ]);

    if ($stock !== null) {
        app(RegisterOpeningStock::class)->handle(
            $context['membership'], $context['warehouse'], $product, $stock, $cost,
        );
    }

    return $product;
}

/** @param array<int, array{product: Product, quantity: int|float|string}> $components */
function regularizationBouquet(array $context, array $components): Product
{
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => fake()->unique()->words(2, true),
        'sku' => fake()->unique()->bothify('RAM-####'),
        'is_sellable' => true,
        'sale_price_base' => 100,
    ]);
    app(CreateProductRecipe::class)->handle($context['membership'], $bouquet, 1, $components);

    return $bouquet;
}

/** @param array<int, array{product: Product, quantity: int|float|string}> $lines */
function confirmRegularizationSale(array $context, array $lines): Sale
{
    return app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'], $lines, [],
        deliveryAt: now()->addDay(),
    );
}

test('sale with sufficient stock consumes normally without pendings', function () {
    $context = regularizationContext();
    $rose = regularizationSupply($context, 'Rosa', 'REG-ALL', '20', '5');
    $bouquet = regularizationBouquet($context, [['product' => $rose, 'quantity' => 2]]);

    $sale = confirmRegularizationSale($context, [['product' => $bouquet, 'quantity' => 2]]);

    expect($sale->inventory_status)->toBe(SaleInventoryStatus::Complete)
        ->and($sale->inventoryPendings)->toBeEmpty()
        ->and(StockBalance::query()->where('product_id', $rose->getKey())->value('quantity'))->toBe('16.000000')
        ->and($sale->stockMovementLinks->sole()->stockMovement->type)->toBe(StockMovementType::Sale);
});

test('sale consumes available components and leaves a zero stock component pending', function () {
    $context = regularizationContext();
    $paper = regularizationSupply($context, 'Papel', 'REG-PAPER', '20', '2');
    $rose = regularizationSupply($context, 'Rosa', 'REG-ZERO', null, '5');
    $bouquet = regularizationBouquet($context, [
        ['product' => $paper, 'quantity' => 3],
        ['product' => $rose, 'quantity' => 10],
    ]);

    $sale = confirmRegularizationSale($context, [['product' => $bouquet, 'quantity' => 1]]);
    $pending = $sale->inventoryPendings->sole();

    expect($sale->inventory_status)->toBe(SaleInventoryStatus::PendingRegularization)
        ->and($sale->total_cost_base)->toBeNull()
        ->and($pending->original_component_product_id)->toBe($rose->getKey())
        ->and($pending->required_quantity)->toBe('10.000000')
        ->and(StockBalance::query()->where('product_id', $paper->getKey())->value('quantity'))->toBe('17.000000')
        ->and(StockBalance::query()->where('product_id', $rose->getKey())->exists())->toBeFalse();
});

test('partial stock is not consumed and the full requirement remains pending', function () {
    $context = regularizationContext();
    $rose = regularizationSupply($context, 'Rosa', 'REG-PART', '6', '5');
    $bouquet = regularizationBouquet($context, [['product' => $rose, 'quantity' => 10]]);

    $sale = confirmRegularizationSale($context, [['product' => $bouquet, 'quantity' => 1]]);

    expect($sale->inventoryPendings->sole()->required_quantity)->toBe('10.000000')
        ->and(StockBalance::query()->where('product_id', $rose->getKey())->value('quantity'))->toBe('6.000000')
        ->and($sale->stockMovementLinks)->toBeEmpty();
});

test('regularization consumes available stock and completes the sale inventory', function () {
    $context = regularizationContext();
    $original = regularizationSupply($context, 'Rosa', 'REG-ORIGINAL', null, '5');
    $actual = regularizationSupply($context, 'Rosa roja', 'REG-ACTUAL', '20', '6');
    $bouquet = regularizationBouquet($context, [['product' => $original, 'quantity' => 10]]);
    $sale = confirmRegularizationSale($context, [['product' => $bouquet, 'quantity' => 1]]);

    $completed = app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $sale->inventoryPendings->sole(), [['product' => $actual, 'quantity' => 10]],
    );

    expect($completed->status)->toBe(SaleInventoryPendingStatus::Completed)
        ->and($completed->stockMovement->type)->toBe(StockMovementType::SaleRegularization)
        ->and($completed->regularizationLines->sole()->actual_product_id)->toBe($actual->getKey())
        ->and($sale->refresh()->inventory_status)->toBe(SaleInventoryStatus::Complete)
        ->and($sale->total_cost_base)->toBe('60.0000')
        ->and(StockBalance::query()->where('product_id', $actual->getKey())->value('quantity'))->toBe('10.000000');
});

test('substitution consumes the actual product without changing the active recipe', function () {
    $context = regularizationContext();
    $chrysanthemum = regularizationSupply($context, 'Crisantemo', 'REG-CRIS', null, '4');
    $daisy = regularizationSupply($context, 'Margarita', 'REG-MARG', '10', '3');
    $bouquet = regularizationBouquet($context, [['product' => $chrysanthemum, 'quantity' => 5]]);
    $recipe = ProductRecipe::query()->where('product_id', $bouquet->getKey())->where('active_slot', 1)->firstOrFail();
    $sale = confirmRegularizationSale($context, [['product' => $bouquet, 'quantity' => 1]]);

    app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $sale->inventoryPendings->sole(), [['product' => $daisy, 'quantity' => 5]],
    );

    expect($recipe->refresh()->items()->sole()->component_product_id)->toBe($chrysanthemum->getKey())
        ->and(StockBalance::query()->where('product_id', $daisy->getKey())->value('quantity'))->toBe('5.000000');
});

test('one pending may be split across multiple actual products', function () {
    $context = regularizationContext();
    $generic = regularizationSupply($context, 'Rosa', 'REG-GEN', null, '5');
    $red = regularizationSupply($context, 'Rosa roja', 'REG-RED', '10', '6');
    $pink = regularizationSupply($context, 'Rosa rosada', 'REG-PINK', '10', '7');
    $bouquet = regularizationBouquet($context, [['product' => $generic, 'quantity' => 10]]);
    $sale = confirmRegularizationSale($context, [['product' => $bouquet, 'quantity' => 1]]);

    $completed = app(RegularizeSaleInventory::class)->handle($context['membership'], $sale->inventoryPendings->sole(), [
        ['product' => $red, 'quantity' => 6],
        ['product' => $pink, 'quantity' => 4],
    ]);

    expect($completed->regularizationLines)->toHaveCount(2)
        ->and($completed->stockMovement->lines)->toHaveCount(2)
        ->and(StockBalance::query()->where('product_id', $red->getKey())->value('quantity'))->toBe('4.000000')
        ->and(StockBalance::query()->where('product_id', $pink->getKey())->value('quantity'))->toBe('6.000000');
});

test('regularization rejects an assigned total different from the pending quantity', function () {
    $context = regularizationContext();
    $original = regularizationSupply($context, 'Rosa', 'REG-TOTAL', null, '5');
    $actual = regularizationSupply($context, 'Rosa roja', 'REG-TOTAL-A', '20', '6');
    $sale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $original, 'quantity' => 10]]), 'quantity' => 1,
    ]]);

    expect(fn () => app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $sale->inventoryPendings->sole(), [['product' => $actual, 'quantity' => 8]],
    ))->toThrow(DomainException::class, 'exactamente 10.000000');
});

test('regularization rejects insufficient stock without creating a movement', function () {
    $context = regularizationContext();
    $original = regularizationSupply($context, 'Rosa', 'REG-INS', null, '5');
    $actual = regularizationSupply($context, 'Rosa roja', 'REG-INS-A', '9', '6');
    $sale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $original, 'quantity' => 10]]), 'quantity' => 1,
    ]]);
    $movementCount = StockMovement::query()->withoutGlobalScope('company')->count();

    expect(fn () => app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $sale->inventoryPendings->sole(), [['product' => $actual, 'quantity' => 10]],
    ))->toThrow(DomainException::class, 'Stock insuficiente')
        ->and(StockMovement::query()->withoutGlobalScope('company')->count())->toBe($movementCount)
        ->and(StockBalance::query()->where('product_id', $actual->getKey())->value('quantity'))->toBe('9.000000');
});

test('completed pending cannot consume inventory twice', function () {
    $context = regularizationContext();
    $original = regularizationSupply($context, 'Rosa', 'REG-IDEM', null, '5');
    $actual = regularizationSupply($context, 'Rosa roja', 'REG-IDEM-A', '20', '6');
    $sale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $original, 'quantity' => 10]]), 'quantity' => 1,
    ]]);
    $pending = $sale->inventoryPendings->sole();
    app(RegularizeSaleInventory::class)->handle($context['membership'], $pending, [['product' => $actual, 'quantity' => 10]]);

    expect(fn () => app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $pending, [['product' => $actual, 'quantity' => 10]],
    ))->toThrow(DomainException::class, 'ya fue regularizado')
        ->and(StockBalance::query()->where('product_id', $actual->getKey())->value('quantity'))->toBe('10.000000');
});

test('owner and administrator may regularize while an operational worker may not', function () {
    $context = regularizationContext();
    $ownerOriginal = regularizationSupply($context, 'Rosa', 'REG-PERM-1', null, '5');
    $actual = regularizationSupply($context, 'Rosa roja', 'REG-PERM-A', '30', '6');
    $ownerSale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $ownerOriginal, 'quantity' => 5]]), 'quantity' => 1,
    ]]);
    $ownerPending = $ownerSale->inventoryPendings->sole();

    app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $ownerPending, [['product' => $actual, 'quantity' => 5]],
    );
    $this->actingAs($context['owner'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('inventory.regularizations'))
        ->assertSuccessful();
    $this->get(route('inventory.regularizations.show', $ownerPending->getKey()))
        ->assertSuccessful();

    $adminUser = User::factory()->create();
    $admin = app(AddMembership::class)->handle($context['membership'], $adminUser, MembershipStatus::Active);
    app(AssignRole::class)->handle($context['membership'], $admin, Role::query()->where('name', 'Administrador')->firstOrFail());
    $adminOriginal = regularizationSupply($context, 'Tulipán', 'REG-PERM-2', null, '5');
    $adminSale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $adminOriginal, 'quantity' => 5]]), 'quantity' => 1,
    ]]);
    app(RegularizeSaleInventory::class)->handle(
        $admin, $adminSale->inventoryPendings->sole(), [['product' => $actual, 'quantity' => 5]],
    );
    $this->actingAs($adminUser)
        ->withSession(['current_membership_id' => $admin->getKey()])
        ->get(route('inventory.regularizations'))
        ->assertSuccessful();

    $workerUser = User::factory()->create();
    $worker = app(AddMembership::class)->handle($context['membership'], $workerUser, MembershipStatus::Active);
    app(AssignRole::class)->handle($context['membership'], $worker, Role::query()->where('name', 'Trabajador')->firstOrFail());
    $workerRole = $worker->roles()->sole();
    $regularizePermission = Permission::query()->where('code', 'inventory.regularize_sales')->firstOrFail();
    $workerRole->permissions()->attach($regularizePermission, ['company_id' => $context['company']->getKey()]);
    $workerOriginal = regularizationSupply($context, 'Lirio', 'REG-PERM-3', null, '5');
    $workerSale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $workerOriginal, 'quantity' => 5]]), 'quantity' => 1,
    ]]);
    $workerPending = $workerSale->inventoryPendings->sole();

    expect(fn () => app(RegularizeSaleInventory::class)->handle(
        $worker, $workerPending, [['product' => $actual, 'quantity' => 5]],
    ))->toThrow(DomainException::class, 'no puede regularizar ventas');
    $this->actingAs($workerUser)
        ->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('inventory.regularizations'))
        ->assertForbidden();
    $this->get(route('inventory.regularizations.show', $workerPending->getKey()))
        ->assertForbidden();
});

test('regularization rejects cross company pending records and products', function () {
    $first = regularizationContext();
    $second = regularizationContext();
    $original = regularizationSupply($first, 'Rosa', 'REG-TENANT', null, '5');
    $actual = regularizationSupply($first, 'Rosa roja', 'REG-TENANT-A', '20', '6');
    $foreign = regularizationSupply($second, 'Rosa extranjera', 'REG-TENANT-X', '20', '4');
    $sale = confirmRegularizationSale($first, [[
        'product' => regularizationBouquet($first, [['product' => $original, 'quantity' => 5]]), 'quantity' => 1,
    ]]);
    $pending = $sale->inventoryPendings->sole();

    expect(fn () => app(RegularizeSaleInventory::class)->handle(
        $second['membership'], $pending, [['product' => $actual, 'quantity' => 5]],
    ))->toThrow(DomainException::class, 'no puede regularizar ventas')
        ->and(fn () => app(RegularizeSaleInventory::class)->handle(
            $first['membership'], $pending, [['product' => $foreign, 'quantity' => 5]],
        ))->toThrow(DomainException::class, 'pertenecer a la empresa');
});

test('a failed split regularization rolls back every allocation', function () {
    $context = regularizationContext();
    $original = regularizationSupply($context, 'Rosa', 'REG-ROLL', null, '5');
    $red = regularizationSupply($context, 'Rosa roja', 'REG-ROLL-R', '10', '6');
    $pink = regularizationSupply($context, 'Rosa rosada', 'REG-ROLL-P', '3', '7');
    $sale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $original, 'quantity' => 10]]), 'quantity' => 1,
    ]]);
    $pending = $sale->inventoryPendings->sole();
    $movementCount = StockMovement::query()->withoutGlobalScope('company')->count();

    expect(fn () => app(RegularizeSaleInventory::class)->handle($context['membership'], $pending, [
        ['product' => $red, 'quantity' => 6],
        ['product' => $pink, 'quantity' => 4],
    ]))->toThrow(DomainException::class, 'Stock insuficiente')
        ->and(StockMovement::query()->withoutGlobalScope('company')->count())->toBe($movementCount)
        ->and(StockBalance::query()->where('product_id', $red->getKey())->value('quantity'))->toBe('10.000000')
        ->and($pending->refresh()->status)->toBe(SaleInventoryPendingStatus::Pending);
});

test('unknown component cost creates an incomplete cost snapshot instead of a false zero', function () {
    $context = regularizationContext();
    $unknown = regularizationSupply($context, 'Flor sin costo', 'REG-NOCOST');
    $sale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $unknown, 'quantity' => 2]]), 'quantity' => 1,
    ]]);

    expect($sale->inventory_status)->toBe(SaleInventoryStatus::PendingRegularization)
        ->and($sale->total_cost_base)->toBeNull()
        ->and($sale->gross_margin_base)->toBeNull()
        ->and($sale->items->sole()->components->sole()->unit_cost_base)->toBeNull()
        ->and($sale->items->sole()->components->sole()->total_cost_base)->toBeNull();
});

test('voiding reverses automatic and regularized consumptions that actually exist', function () {
    $context = regularizationContext();
    $paper = regularizationSupply($context, 'Papel', 'REG-VOID-P', '10', '2');
    $original = regularizationSupply($context, 'Rosa', 'REG-VOID-O', null, '5');
    $actual = regularizationSupply($context, 'Rosa roja', 'REG-VOID-A', '10', '6');
    $bouquet = regularizationBouquet($context, [
        ['product' => $paper, 'quantity' => 2],
        ['product' => $original, 'quantity' => 5],
    ]);
    $sale = confirmRegularizationSale($context, [['product' => $bouquet, 'quantity' => 1]]);
    app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $sale->inventoryPendings->sole(), [['product' => $actual, 'quantity' => 5]],
    );

    app(VoidSale::class)->handle($context['membership'], $sale->refresh(), 'Pedido anulado');

    expect(StockBalance::query()->where('product_id', $paper->getKey())->value('quantity'))->toBe('10.000000')
        ->and(StockBalance::query()->where('product_id', $actual->getKey())->value('quantity'))->toBe('10.000000')
        ->and(StockMovement::query()->where('type', StockMovementType::Reversal)->count())->toBe(2);
});

test('voiding a fully pending sale cancels the pending without creating inventory movements', function () {
    $context = regularizationContext();
    $original = regularizationSupply($context, 'Rosa', 'REG-VOID-ONLY', null, '5');
    $sale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $original, 'quantity' => 5]]), 'quantity' => 1,
    ]]);
    $pending = $sale->inventoryPendings->sole();
    $movementCount = StockMovement::query()->withoutGlobalScope('company')->count();

    app(VoidSale::class)->handle($context['membership'], $sale, 'Pedido cancelado');

    expect($pending->refresh()->status)->toBe(SaleInventoryPendingStatus::Cancelled)
        ->and($sale->refresh()->inventory_status)->toBe(SaleInventoryStatus::Cancelled)
        ->and(StockMovement::query()->withoutGlobalScope('company')->count())->toBe($movementCount);
});

test('a sale regularization movement cannot be reversed outside VoidSale', function () {
    $context = regularizationContext();
    $original = regularizationSupply($context, 'Rosa', 'REG-REV-O', null, '5');
    $actual = regularizationSupply($context, 'Rosa roja', 'REG-REV-A', '10', '6');
    $sale = confirmRegularizationSale($context, [[
        'product' => regularizationBouquet($context, [['product' => $original, 'quantity' => 5]]), 'quantity' => 1,
    ]]);
    $pending = app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $sale->inventoryPendings->sole(), [['product' => $actual, 'quantity' => 5]],
    );

    expect(fn () => app(ReverseStockMovement::class)->handle(
        $context['membership'], $pending->stockMovement,
    ))->toThrow(DomainException::class, 'solo pueden revertirse anulando la venta');
});
