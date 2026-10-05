<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\ConfirmSaleOnce;
use App\Actions\Sales\RegularizeSaleInventory;
use App\Enums\CompanyModuleStatus;
use App\Enums\InventoryBehavior;
use App\Enums\ModuleCode;
use App\Enums\ProductItemType;
use App\Enums\SaleInventoryStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership, branch: Branch, warehouse: Warehouse, unit: Unit} */
function substitutionContext(string $name = 'Florería sustituciones'): array
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
        'code' => fake()->unique()->bothify('SUB-###'),
        'symbol' => 'UND',
        'decimal_places' => 6,
    ]);

    return compact('user', 'company', 'membership', 'branch', 'warehouse', 'unit');
}

function substitutionSupply(
    array $context,
    string $name,
    string $sku,
    ?string $stock = null,
    ?string $cost = '1',
    array $attributes = [],
): Product {
    $product = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => $name,
        'sku' => $sku,
        'is_sellable' => false,
        'fallback_unit_cost_base' => $cost,
        ...$attributes,
    ]);

    if ($stock !== null && $cost !== null) {
        app(RegisterOpeningStock::class)->handle(
            $context['membership'], $context['warehouse'], $product, $stock, $cost,
        );
    } elseif ($stock !== null) {
        StockBalance::factory()->create([
            'company_id' => $context['company']->getKey(),
            'warehouse_id' => $context['warehouse']->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $stock,
            'inventory_value_base' => '0',
            'average_unit_cost_base' => '0',
            'last_inbound_unit_cost_base' => null,
        ]);
    }

    return $product;
}

/** @param array<int, array{product: Product, quantity: int|float|string}> $components */
function substitutionBouquet(array $context, array $components, string $name = 'Ramo Primavera'): Product
{
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => $name,
        'sku' => fake()->unique()->bothify('RAM-SUB-###'),
        'is_sellable' => true,
        'sale_price_base' => '100',
    ]);
    app(CreateProductRecipe::class)->handle($context['membership'], $bouquet, 1, $components);

    return $bouquet;
}

/** @param array<int, array<string, mixed>> $lines */
function confirmSubstitutionSale(array $context, array $lines): Sale
{
    return app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'], $lines, [],
        deliveryAt: now()->addDay(),
    );
}

test('a sale without overrides keeps the existing recipe consumption behavior', function () {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', 'SUB-A-BASE', '20', '2');
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);

    $sale = confirmSubstitutionSale($context, [['product' => $bouquet, 'quantity' => 2]]);
    $snapshot = $sale->items->sole()->components->sole();

    expect($snapshot->original_product_id)->toBe($original->getKey())
        ->and($snapshot->product_id)->toBe($original->getKey())
        ->and($snapshot->original_quantity_required)->toBe('10.000000')
        ->and($snapshot->quantity_consumed)->toBe('10.000000')
        ->and($sale->inventoryPendings)->toBeEmpty()
        ->and(StockBalance::query()->where('product_id', $original->getKey())->value('quantity'))->toBe('10.000000');
});

test('an override consumes only the substitute quantity and uses its historical cost', function () {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', 'SUB-A-COST', '20', '9');
    $substitute = substitutionSupply($context, 'Popelina', 'SUB-B-COST', '20', '3');
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);

    $sale = confirmSubstitutionSale($context, [[
        'product' => $bouquet,
        'quantity' => 1,
        'component_overrides' => [[
            'original_product_id' => $original->getKey(),
            'product' => $substitute,
            'quantity' => 3,
        ]],
    ]]);
    $snapshot = $sale->items->sole()->components->sole();
    $movementLine = $sale->stockMovementLinks->sole()->stockMovement->lines->sole();

    expect($snapshot->original_product_id)->toBe($original->getKey())
        ->and($snapshot->original_quantity_required)->toBe('5.000000')
        ->and($snapshot->product_id)->toBe($substitute->getKey())
        ->and($snapshot->quantity_required)->toBe('3.000000')
        ->and($snapshot->quantity_consumed)->toBe('3.000000')
        ->and($snapshot->unit_cost_base)->toBe('3.0000')
        ->and($snapshot->total_cost_base)->toBe('9.0000')
        ->and($sale->total_cost_base)->toBe('9.0000')
        ->and($movementLine->product_id)->toBe($substitute->getKey())
        ->and($movementLine->quantity)->toBe('-3.000000')
        ->and(StockBalance::query()->where('product_id', $original->getKey())->value('quantity'))->toBe('20.000000')
        ->and(StockBalance::query()->where('product_id', $substitute->getKey())->value('quantity'))->toBe('17.000000');

    app(CurrentCompany::class)->set($context['membership']);
    session()->put('current_membership_id', $context['membership']->getKey());
    Livewire::actingAs($context['user'])
        ->test('pages::sales.show', ['saleId' => $sale->getKey()])
        ->assertSee('Sustituciones de ingredientes')
        ->assertSee('Chispas → Popelina');
});

test('an unavailable substitute creates a traced pending without partial consumption', function (?string $stock, ?string $cost) {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', fake()->unique()->bothify('SUB-PEND-A-###'), '20', '9');
    $substitute = substitutionSupply($context, 'Popelina', fake()->unique()->bothify('SUB-PEND-B-###'), $stock, $cost);
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);

    $sale = confirmSubstitutionSale($context, [[
        'product' => $bouquet,
        'quantity' => 1,
        'component_overrides' => [[
            'original_product_id' => $original->getKey(), 'product' => $substitute, 'quantity' => 5,
        ]],
    ]]);
    $pending = $sale->inventoryPendings->sole();

    expect($sale->inventory_status)->toBe(SaleInventoryStatus::PendingRegularization)
        ->and($sale->total_cost_base)->toBeNull()
        ->and($pending->original_component_product_id)->toBe($original->getKey())
        ->and($pending->planned_component_product_id)->toBe($substitute->getKey())
        ->and($pending->required_quantity)->toBe('5.000000')
        ->and($pending->componentSnapshot->original_product_id)->toBe($original->getKey())
        ->and($pending->componentSnapshot->product_id)->toBe($substitute->getKey())
        ->and(StockBalance::query()->where('product_id', $substitute->getKey())->value('quantity'))
        ->toBe($stock === null ? null : $stock.'.000000');
})->with([
    'insufficient stock' => ['3', '2'],
    'unknown cost' => [null, null],
]);

test('the master recipe remains unchanged and the next sale returns to the original ingredient', function () {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', 'SUB-RECIPE-A', '30', '2');
    $substitute = substitutionSupply($context, 'Popelina', 'SUB-RECIPE-B', '30', '3');
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);
    $recipe = ProductRecipe::query()->where('product_id', $bouquet->getKey())->where('active_slot', 1)->firstOrFail();

    confirmSubstitutionSale($context, [[
        'product' => $bouquet,
        'quantity' => 1,
        'component_overrides' => [[
            'original_product_id' => $original->getKey(), 'product' => $substitute, 'quantity' => 3,
        ]],
    ]]);
    $secondSale = confirmSubstitutionSale($context, [['product' => $bouquet, 'quantity' => 1]]);

    expect($recipe->refresh()->items()->sole()->component_product_id)->toBe($original->getKey())
        ->and($recipe->items()->sole()->quantity)->toBe('5.000000')
        ->and($secondSale->items->sole()->components->sole()->product_id)->toBe($original->getKey())
        ->and(StockBalance::query()->where('product_id', $original->getKey())->value('quantity'))->toBe('25.000000')
        ->and(StockBalance::query()->where('product_id', $substitute->getKey())->value('quantity'))->toBe('27.000000');
});

test('only the selected component is replaced when a bouquet has several ingredients', function () {
    $context = substitutionContext();
    $chispas = substitutionSupply($context, 'Chispas', 'SUB-MULTI-A', '20', '2');
    $rosas = substitutionSupply($context, 'Rosas', 'SUB-MULTI-R', '20', '4');
    $popelina = substitutionSupply($context, 'Popelina', 'SUB-MULTI-B', '20', '3');
    $bouquet = substitutionBouquet($context, [
        ['product' => $chispas, 'quantity' => 5],
        ['product' => $rosas, 'quantity' => 12],
    ]);

    $sale = confirmSubstitutionSale($context, [[
        'product' => $bouquet,
        'quantity' => 1,
        'component_overrides' => [[
            'original_product_id' => $chispas->getKey(), 'product' => $popelina, 'quantity' => 3,
        ]],
    ]]);
    $components = $sale->items->sole()->components->keyBy('original_product_id');

    expect($components[$chispas->getKey()]->product_id)->toBe($popelina->getKey())
        ->and($components[$chispas->getKey()]->quantity_consumed)->toBe('3.000000')
        ->and($components[$rosas->getKey()]->product_id)->toBe($rosas->getKey())
        ->and($components[$rosas->getKey()]->quantity_consumed)->toBe('12.000000');
});

test('overrides are isolated by sale line when different bouquets use the same ingredient', function () {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', 'SUB-LINE-A', '30', '2');
    $substitute = substitutionSupply($context, 'Popelina', 'SUB-LINE-B', '30', '3');
    $firstBouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]], 'Ramo uno');
    $secondBouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 2]], 'Ramo dos');

    $sale = confirmSubstitutionSale($context, [
        [
            'product' => $firstBouquet,
            'quantity' => 2,
            'component_overrides' => [[
                'original_product_id' => $original->getKey(), 'product' => $substitute, 'quantity' => 7,
            ]],
        ],
        ['product' => $secondBouquet, 'quantity' => 1],
    ]);
    $items = $sale->items->keyBy('product_id');

    expect($items[$firstBouquet->getKey()]->components->sole()->product_id)->toBe($substitute->getKey())
        ->and($items[$firstBouquet->getKey()]->components->sole()->quantity_consumed)->toBe('7.000000')
        ->and($items[$secondBouquet->getKey()]->components->sole()->product_id)->toBe($original->getKey())
        ->and($items[$secondBouquet->getKey()]->components->sole()->quantity_consumed)->toBe('2.000000');
});

test('invalid substitute products and quantities are rejected server side', function () {
    $context = substitutionContext();
    $otherCompany = substitutionContext('Otra empresa');
    $original = substitutionSupply($context, 'Chispas', 'SUB-VALID-A', '20', '2');
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);
    $foreign = substitutionSupply($otherCompany, 'Ajeno', 'SUB-FOREIGN', '20', '3');
    $inactive = substitutionSupply($context, 'Inactivo', 'SUB-INACTIVE', '20', '3', ['is_active' => false]);
    $service = substitutionSupply($context, 'Servicio', 'SUB-SERVICE', null, '3', [
        'item_type' => ProductItemType::Service,
        'inventory_behavior' => InventoryBehavior::None,
    ]);

    foreach ([$foreign, $inactive, $service] as $invalid) {
        expect(fn () => confirmSubstitutionSale($context, [[
            'product' => $bouquet,
            'quantity' => 1,
            'component_overrides' => [[
                'original_product_id' => $original->getKey(), 'product' => $invalid, 'quantity' => 5,
            ]],
        ]]))->toThrow(DomainException::class);
    }

    foreach ([0, -1] as $invalidQuantity) {
        expect(fn () => confirmSubstitutionSale($context, [[
            'product' => $bouquet,
            'quantity' => 1,
            'component_overrides' => [[
                'original_product_id' => $original->getKey(), 'product' => $original, 'quantity' => $invalidQuantity,
            ]],
        ]]))->toThrow(DomainException::class, 'cantidad positiva');
    }

    expect(Sale::query()->withoutGlobalScope('company')->count())->toBe(0);
});

test('regularization preserves original planned and actual ingredient traceability', function () {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', 'SUB-REG-A', '20', '9');
    $planned = substitutionSupply($context, 'Popelina', 'SUB-REG-B', '2', '3');
    $actual = substitutionSupply($context, 'Tela', 'SUB-REG-C', '20', '4');
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);
    $sale = confirmSubstitutionSale($context, [[
        'product' => $bouquet,
        'quantity' => 1,
        'component_overrides' => [[
            'original_product_id' => $original->getKey(), 'product' => $planned, 'quantity' => 5,
        ]],
    ]]);

    $completed = app(RegularizeSaleInventory::class)->handle(
        $context['membership'], $sale->inventoryPendings->sole(), [['product' => $actual, 'quantity' => 5]],
    );

    expect($completed->original_component_product_id)->toBe($original->getKey())
        ->and($completed->planned_component_product_id)->toBe($planned->getKey())
        ->and($completed->regularizationLines->sole()->actual_product_id)->toBe($actual->getKey())
        ->and($completed->componentSnapshot->original_product_id)->toBe($original->getKey())
        ->and($completed->componentSnapshot->product_id)->toBe($planned->getKey())
        ->and($sale->refresh()->total_cost_base)->toBe('20.0000');
});

test('retrying the same override attempt returns one sale and one inventory movement', function () {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', 'SUB-IDEM-A', '20', '2');
    $substitute = substitutionSupply($context, 'Popelina', 'SUB-IDEM-B', '20', '3');
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);
    $token = Str::uuid()->toString();
    $deliveryAt = now()->addDay();
    $lines = [[
        'product' => $bouquet,
        'quantity' => 1,
        'component_overrides' => [[
            'original_product_id' => $original->getKey(), 'product' => $substitute, 'quantity' => 5,
        ]],
    ]];

    $first = app(ConfirmSaleOnce::class)->handle(
        $token, $context['membership'], $context['branch'], $context['warehouse'], $lines, [],
        deliveryAt: $deliveryAt,
    );
    $second = app(ConfirmSaleOnce::class)->handle(
        $token, $context['membership'], $context['branch'], $context['warehouse'], $lines, [],
        deliveryAt: $deliveryAt,
    );

    expect($second->is($first))->toBeTrue()
        ->and(Sale::query()->count())->toBe(1)
        ->and(StockMovement::query()->whereHas('saleLinks', fn ($query) => $query->where('sale_id', $first->getKey()))->count())->toBe(1)
        ->and(StockBalance::query()->where('product_id', $substitute->getKey())->value('quantity'))->toBe('15.000000');
});

test('the sales form exposes substitutions and changing one changes the duplicate approval fingerprint', function () {
    $context = substitutionContext();
    $original = substitutionSupply($context, 'Chispas', 'SUB-UI-A', '30', '2');
    $firstSubstitute = substitutionSupply($context, 'Popelina', 'SUB-UI-B', '30', '3');
    $secondSubstitute = substitutionSupply($context, 'Tela', 'SUB-UI-C', '30', '4');
    $bouquet = substitutionBouquet($context, [['product' => $original, 'quantity' => 5]]);
    $delivery = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [['product' => $bouquet, 'quantity' => 1]], [], '62722154', deliveryAt: $delivery,
    );
    app(CurrentCompany::class)->set($context['membership']);
    session()->put('current_membership_id', $context['membership']->getKey());

    $component = Livewire::actingAs($context['user'])
        ->test('pages::sales.index')
        ->call('openSale')
        ->set('customerName', '+591 62722154')
        ->set('deliveryAt', $delivery->setTimezone($context['company']->timezone)->format('Y-m-d\TH:i'))
        ->set('saleLines.0.product_id', $bouquet->getKey())
        ->call('startComponentSubstitution', 0, $original->getKey())
        ->set("saleLines.0.component_overrides.{$original->getKey()}.substitute_product_id", $firstSubstitute->getKey())
        ->set("saleLines.0.component_overrides.{$original->getKey()}.quantity", '5')
        ->assertSee('Ingredientes de la receta')
        ->assertSee('Chispas')
        ->assertSee('Popelina')
        ->call('confirmSale')
        ->assertHasNoErrors();
    $firstFingerprint = $component->get('duplicateWarningFingerprint');

    $component
        ->set("saleLines.0.component_overrides.{$original->getKey()}.substitute_product_id", $secondSubstitute->getKey())
        ->call('confirmSale');

    expect($firstFingerprint)->not->toBe('')
        ->and($component->get('duplicateWarningFingerprint'))->not->toBe($firstFingerprint)
        ->and(Sale::query()->count())->toBe(1);
});
