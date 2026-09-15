<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RecordManualInbound;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Inventory\ReverseStockMovement;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\VoidSale;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleStockMovementKind;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Authorization\CompanyAccess;
use Database\Seeders\DatabaseSeeder;
use DomainException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{user: User, company: Company, membership: Membership, branch: Branch, warehouse: Warehouse, unit: Unit} */
function salesContext(bool $allowNegativeStock = false): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => fake()->unique()->company(),
        'base_currency_id' => Currency::query()->where('code', 'BOB')->firstOrFail()->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
        'allow_negative_stock' => $allowNegativeStock,
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())
        ->where('user_id', $user->getKey())
        ->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory, ModuleCode::Sales] as $moduleCode) {
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
        'symbol' => 'u',
        'decimal_places' => 0,
    ]);

    return compact('user', 'company', 'membership', 'branch', 'warehouse', 'unit');
}

/** @param array<string, mixed> $attributes */
function salesSupply(array $context, string $name, string $sku, string $stock, string $cost, array $attributes = []): Product
{
    $product = Product::factory()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => $name,
        'sku' => $sku,
        'is_sellable' => false,
        'sale_price_base' => 0,
        'fallback_unit_cost_base' => $cost,
        ...$attributes,
    ]);
    app(RegisterOpeningStock::class)->handle(
        $context['membership'], $context['warehouse'], $product, $stock, $cost,
    );

    return $product;
}

/** @param array<int, array{product: Product, quantity: int|float|string, waste_percentage?: int|float|string}> $components */
function salesBouquet(array $context, array $components, string $price = '80'): Product
{
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $context['company']->getKey(),
        'unit_id' => $context['unit']->getKey(),
        'name' => 'Ramo Amor',
        'sku' => fake()->unique()->bothify('RAMO-####'),
        'is_sellable' => true,
        'sale_price_base' => $price,
    ]);
    app(CreateProductRecipe::class)->handle($context['membership'], $bouquet, 1, $components);

    return $bouquet;
}

/** @param array<int, array{product: Product, quantity: int|float|string}> $lines */
function confirmTestSale(array $context, array $lines, array $payments, ?string $customer = null): Sale
{
    return app(ConfirmSale::class)->handle(
        $context['membership'],
        $context['branch'],
        $context['warehouse'],
        $lines,
        $payments,
        $customer,
        deliveryAt: now()->addDay(),
    );
}

test('confirming a bouquet sale consumes its active recipe and keeps historical cost', function () {
    $context = salesContext();
    $rose = salesSupply($context, 'Rosa Roja', 'ROSA-001', '100', '5');
    $tulip = salesSupply($context, 'Tulipán', 'TUL-001', '60', '7');
    $paper = salesSupply($context, 'Papel', 'PAP-001', '50', '2');
    $ribbon = salesSupply($context, 'Cinta', 'CIN-001', '80', '1');
    $bouquet = salesBouquet($context, [
        ['product' => $rose, 'quantity' => 6],
        ['product' => $tulip, 'quantity' => 2],
        ['product' => $paper, 'quantity' => 1],
        ['product' => $ribbon, 'quantity' => 1],
    ]);
    $recipe = $bouquet->recipes()->where('active_slot', 1)->firstOrFail();
    $cash = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('code', 'cash')->firstOrFail();

    $sale = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 2]], [
        ['payment_method' => $cash, 'amount_base' => 160],
    ], 'Ana');

    expect($sale->number)->toBe('V-000001')
        ->and($sale->total_base)->toBe('160.0000')
        ->and($sale->total_cost_base)->toBe('94.0000')
        ->and($sale->gross_margin_base)->toBe('66.0000')
        ->and($sale->items->firstOrFail()->product_recipe_id)->toBe($recipe->getKey())
        ->and($sale->items->firstOrFail()->recipe_version)->toBe(1)
        ->and($sale->items->firstOrFail()->components)->toHaveCount(4);

    expect(StockBalance::query()->where('warehouse_id', $context['warehouse']->getKey())->where('product_id', $rose->getKey())->value('quantity'))->toBe('88.000000')
        ->and(StockBalance::query()->where('product_id', $tulip->getKey())->value('quantity'))->toBe('56.000000')
        ->and(StockBalance::query()->where('product_id', $paper->getKey())->value('quantity'))->toBe('48.000000')
        ->and(StockBalance::query()->where('product_id', $ribbon->getKey())->value('quantity'))->toBe('78.000000');

    $link = $sale->stockMovementLinks->firstOrFail();
    expect($link->kind)->toBe(SaleStockMovementKind::Consumption)
        ->and($link->stockMovement->type)->toBe(StockMovementType::Sale)
        ->and($link->stockMovement->reason)->toBe('Venta V-000001')
        ->and($link->stockMovement->lines)->toHaveCount(4);
});

test('insufficient stock confirms the sale with a full pending regularization', function () {
    $context = salesContext(true);
    $rose = salesSupply($context, 'Rosa Roja', 'ROSA-LOW', '5', '5');
    $paper = salesSupply($context, 'Papel', 'PAP-SAFE', '10', '2');
    $bouquet = salesBouquet($context, [
        ['product' => $rose, 'quantity' => 6],
        ['product' => $paper, 'quantity' => 1],
    ]);
    $cash = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('code', 'cash')->firstOrFail();
    $sale = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 1]], [
        ['payment_method' => $cash, 'amount_base' => 80],
    ]);

    expect($sale->inventory_status)->toBe(SaleInventoryStatus::PendingRegularization)
        ->and($sale->inventoryPendings)->toHaveCount(1)
        ->and($sale->inventoryPendings->sole()->required_quantity)->toBe('6.000000')
        ->and(StockBalance::query()->where('product_id', $rose->getKey())->value('quantity'))->toBe('5.000000')
        ->and(StockBalance::query()->where('product_id', $paper->getKey())->value('quantity'))->toBe('9.000000');
});

test('split payments may cover all or part of the sale total', function () {
    $context = salesContext();
    $rose = salesSupply($context, 'Rosa', 'ROSA-PAY', '20', '5');
    $bouquet = salesBouquet($context, [['product' => $rose, 'quantity' => 1]], '80');
    $methods = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->get()->keyBy('code');

    $sale = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 1]], [
        ['payment_method' => $methods->get('cash'), 'amount_base' => 30],
        ['payment_method' => $methods->get('qr'), 'amount_base' => 50],
    ]);

    expect($sale->payments)->toHaveCount(2)
        ->and($sale->payments->sum('amount_base'))->toEqual(80.0);

    $partial = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 1]], [
        ['payment_method' => $methods->get('cash'), 'amount_base' => 79],
    ]);

    expect($partial->paid_total_base)->toBe('79.0000')
        ->and($partial->balance_due_base)->toBe('1.0000');
});

test('one sale supports multiple bouquets and aggregates shared component consumption', function () {
    $context = salesContext();
    $rose = salesSupply($context, 'Rosa', 'ROSA-MULTI', '100', '2');
    $firstBouquet = salesBouquet($context, [['product' => $rose, 'quantity' => 2, 'waste_percentage' => 50]], '10');
    $secondBouquet = salesBouquet($context, [['product' => $rose, 'quantity' => 3]], '20');
    $cash = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('code', 'cash')->firstOrFail();

    $sale = confirmTestSale($context, [
        ['product' => $firstBouquet, 'quantity' => 2],
        ['product' => $secondBouquet, 'quantity' => 1],
    ], [['payment_method' => $cash, 'amount_base' => 40]]);

    expect($sale->items)->toHaveCount(2)
        ->and($sale->total_base)->toBe('40.0000')
        ->and($sale->total_cost_base)->toBe('18.0000')
        ->and($sale->stockMovementLinks->firstOrFail()->stockMovement->lines)->toHaveCount(1)
        ->and($sale->stockMovementLinks->firstOrFail()->stockMovement->lines->firstOrFail()->quantity)->toBe('-9.000000')
        ->and(StockBalance::query()->where('product_id', $rose->getKey())->value('quantity'))->toBe('91.000000');
});

test('later purchases do not change historical sale cost', function () {
    $context = salesContext();
    $rose = salesSupply($context, 'Rosa', 'ROSA-COST', '20', '5');
    $bouquet = salesBouquet($context, [['product' => $rose, 'quantity' => 2]], '20');
    $cash = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('code', 'cash')->firstOrFail();
    $sale = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 1]], [
        ['payment_method' => $cash, 'amount_base' => 20],
    ]);

    app(RecordManualInbound::class)->handle($context['membership'], $context['warehouse'], $rose, 20, 15);

    expect($sale->refresh()->total_cost_base)->toBe('10.0000')
        ->and($sale->items()->firstOrFail()->components()->firstOrFail()->unit_cost_base)->toBe('5.0000')
        ->and(StockBalance::query()->where('product_id', $rose->getKey())->value('average_unit_cost_base'))->not->toBe('5.0000');
});

test('voiding a sale creates a reversal and restores stock without deleting history', function () {
    $context = salesContext();
    $rose = salesSupply($context, 'Rosa', 'ROSA-VOID', '20', '5');
    $bouquet = salesBouquet($context, [['product' => $rose, 'quantity' => 2]], '20');
    $sale = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 2]], []);
    $original = $sale->stockMovementLinks->firstOrFail()->stockMovement;
    $originalAttributes = $original->getAttributes();

    expect(fn () => app(ReverseStockMovement::class)->handle($context['membership'], $original, 'Incorrecto'))
        ->toThrow(DomainException::class, 'anulando la venta');

    $voided = app(VoidSale::class)->handle($context['membership'], $sale, 'Error de registro');

    expect($voided->status)->toBe(SaleStatus::Voided)
        ->and($voided->void_reason)->toBe('Error de registro')
        ->and($voided->stockMovementLinks)->toHaveCount(2)
        ->and($voided->stockMovementLinks->firstWhere('kind', SaleStockMovementKind::Reversal))->not->toBeNull()
        ->and(StockBalance::query()->where('product_id', $rose->getKey())->value('quantity'))->toBe('20.000000')
        ->and($original->refresh()->getAttributes())->toEqual($originalAttributes)
        ->and(Sale::query()->count())->toBe(1);

    expect(fn () => app(VoidSale::class)->handle($context['membership'], $voided, 'Otra vez'))
        ->toThrow(DomainException::class, 'ya está anulada');
});

test('sale numbering is sequential per company', function () {
    $first = salesContext();
    $second = salesContext();

    foreach ([$first, $second] as $context) {
        $supply = salesSupply($context, 'Rosa', fake()->unique()->bothify('SEQ-###'), '10', '1');
        $bouquet = salesBouquet($context, [['product' => $supply, 'quantity' => 1]], '10');
        $cash = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('code', 'cash')->firstOrFail();
        $sale = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 1]], [['payment_method' => $cash, 'amount_base' => 10]]);
        expect($sale->number)->toBe('V-000001');
    }
});

test('companies cannot use foreign sale resources', function () {
    $first = salesContext();
    $second = salesContext();
    $supply = salesSupply($first, 'Rosa', 'ROSA-TENANT', '10', '1');
    $bouquet = salesBouquet($first, [['product' => $supply, 'quantity' => 1]], '10');
    $foreignCash = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $second['company']->getKey())->where('code', 'cash')->firstOrFail();

    expect(fn () => confirmTestSale($first, [['product' => $bouquet, 'quantity' => 1]], [
        ['payment_method' => $foreignCash, 'amount_base' => 10],
    ]))->toThrow(DomainException::class, 'métodos de pago')
        ->and(fn () => app(ConfirmSale::class)->handle(
            $first['membership'], $second['branch'], $second['warehouse'],
            [['product' => $bouquet, 'quantity' => 1]],
            [['payment_method' => $foreignCash, 'amount_base' => 10]],
        ))->toThrow(DomainException::class, 'pertenecer a la empresa');
});

test('disabled sales module blocks new sales while preserving historical reads', function () {
    $context = salesContext();
    $supply = salesSupply($context, 'Rosa', 'ROSA-MODULE', '10', '1');
    $bouquet = salesBouquet($context, [['product' => $supply, 'quantity' => 1]], '10');
    $cash = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('code', 'cash')->firstOrFail();
    $sale = confirmTestSale($context, [['product' => $bouquet, 'quantity' => 1]], [['payment_method' => $cash, 'amount_base' => 10]]);
    app(SetModuleStatus::class)->handle(
        $context['membership'], Module::query()->where('code', ModuleCode::Sales)->firstOrFail(), CompanyModuleStatus::Disabled,
    );

    expect(app(CompanyAccess::class)->allows($context['user'], $context['company'], 'sales.view', mutation: false))->toBeTrue()
        ->and(fn () => confirmTestSale($context, [['product' => $bouquet, 'quantity' => 1]], [['payment_method' => $cash, 'amount_base' => 10]]))
        ->toThrow(DomainException::class, 'no puede registrar ventas')
        ->and($sale->refresh()->status)->toBe(SaleStatus::Confirmed);
});
