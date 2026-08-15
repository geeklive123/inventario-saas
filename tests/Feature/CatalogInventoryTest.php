<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RecordManualInbound;
use App\Actions\Inventory\RecordManualOutbound;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Inventory\ReverseStockMovement;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeItem;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/**
 * @return array{company: Company, membership: Membership, warehouse: Warehouse}
 */
function inventoryContext(bool $allowNegativeStock = false): array
{
    $owner = User::factory()->create();
    $currency = Currency::query()->where('code', 'BOB')->firstOrFail();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => fake()->unique()->company(),
        'base_currency_id' => $currency->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
        'allow_negative_stock' => $allowNegativeStock,
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

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();

    return compact('company', 'membership', 'warehouse');
}

function inventoryProduct(Company $company, array $attributes = []): Product
{
    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);

    return Product::factory()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
        ...$attributes,
    ]);
}

test('catalog data is isolated between companies', function () {
    $first = inventoryContext();
    $second = inventoryContext();
    $firstProduct = inventoryProduct($first['company']);
    $secondProduct = inventoryProduct($second['company']);

    app(CurrentCompany::class)->set($first['membership']);

    expect(Product::query()->find($firstProduct->getKey()))->not->toBeNull()
        ->and(Product::query()->find($secondProduct->getKey()))->toBeNull();
});

test('sku and non-null barcode are unique per company', function () {
    $first = inventoryContext();
    $second = inventoryContext();
    inventoryProduct($first['company'], ['sku' => 'SAME', 'barcode' => '123']);
    inventoryProduct($second['company'], ['sku' => 'SAME', 'barcode' => '123']);
    inventoryProduct($first['company'], ['sku' => 'NULL-1', 'barcode' => null]);
    inventoryProduct($first['company'], ['sku' => 'NULL-2', 'barcode' => null]);

    expect(fn () => inventoryProduct($first['company'], [
        'sku' => 'SAME',
        'barcode' => 'DIFFERENT',
    ]))->toThrow(QueryException::class)
        ->and(fn () => inventoryProduct($first['company'], [
            'sku' => 'DIFFERENT',
            'barcode' => '123',
        ]))->toThrow(QueryException::class);
});

test('services cannot create stock balances', function () {
    $context = inventoryContext();
    $service = inventoryProduct($context['company'], [
        'item_type' => 'service',
        'inventory_behavior' => 'none',
    ]);

    expect(fn () => app(RegisterOpeningStock::class)->handle(
        $context['membership'],
        $context['warehouse'],
        $service,
        1,
        10,
    ))->toThrow(DomainException::class, 'self-managed physical products');

    expect(StockBalance::query()->count())->toBe(0);
});

test('self-managed physical products can hold stock', function () {
    $context = inventoryContext();
    $product = inventoryProduct($context['company']);

    app(RegisterOpeningStock::class)->handle(
        $context['membership'],
        $context['warehouse'],
        $product,
        5,
        10,
    );

    $balance = StockBalance::query()->where('product_id', $product->getKey())->firstOrFail();

    expect($balance->quantity)->toBe('5.000000')
        ->and($balance->inventory_value_base)->toBe('50.0000')
        ->and($balance->average_unit_cost_base)->toBe('10.0000');
});

test('recipe components must belong to the same company', function () {
    $first = inventoryContext();
    $second = inventoryContext();
    $composed = inventoryProduct($first['company'], [
        'inventory_behavior' => 'components',
    ]);
    $foreignComponent = inventoryProduct($second['company']);

    expect(fn () => app(CreateProductRecipe::class)->handle(
        $first['membership'],
        $composed,
        1,
        [['product' => $foreignComponent, 'quantity' => 1]],
    ))->toThrow(DomainException::class, 'same company');
});

test('a product has only one active versioned recipe', function () {
    $context = inventoryContext();
    $composed = inventoryProduct($context['company'], ['inventory_behavior' => 'components']);
    $component = inventoryProduct($context['company']);

    $first = app(CreateProductRecipe::class)->handle(
        $context['membership'],
        $composed,
        1,
        [['product' => $component, 'quantity' => 2, 'waste_percentage' => 5]],
    );
    $second = app(CreateProductRecipe::class)->handle(
        $context['membership'],
        $composed,
        1,
        [['product' => $component, 'quantity' => 3]],
    );

    expect($first->refresh()->isActive())->toBeFalse()
        ->and($second->isActive())->toBeTrue()
        ->and($second->version)->toBe(2)
        ->and(ProductRecipe::query()->where('product_id', $composed->getKey())->where('active_slot', 1)->count())->toBe(1);
});

test('an active recipe and its items cannot be edited destructively', function () {
    $context = inventoryContext();
    $composed = inventoryProduct($context['company'], ['inventory_behavior' => 'components']);
    $component = inventoryProduct($context['company']);
    $recipe = app(CreateProductRecipe::class)->handle(
        $context['membership'],
        $composed,
        1,
        [['product' => $component, 'quantity' => 2]],
    );

    expect(fn () => $recipe->update(['yield_quantity' => 2]))
        ->toThrow(DomainException::class, 'cannot be edited')
        ->and(fn () => $recipe->items->firstOrFail()->update(['quantity' => 3]))
        ->toThrow(DomainException::class, 'cannot be changed')
        ->and(fn () => ProductRecipeItem::query()->create([
            'company_id' => $context['company']->getKey(),
            'product_recipe_id' => $recipe->getKey(),
            'component_product_id' => inventoryProduct($context['company'])->getKey(),
            'quantity' => 1,
            'waste_percentage' => 0,
        ]))->toThrow(DomainException::class, 'cannot be changed');
});

test('a composed product cannot consume another composed product', function () {
    $context = inventoryContext();
    $composed = inventoryProduct($context['company'], ['inventory_behavior' => 'components']);
    $composedComponent = inventoryProduct($context['company'], ['inventory_behavior' => 'components']);

    expect(fn () => app(CreateProductRecipe::class)->handle(
        $context['membership'],
        $composed,
        1,
        [['product' => $composedComponent, 'quantity' => 1]],
    ))->toThrow(DomainException::class, 'non-composed physical product');
});

test('an inbound movement recalculates weighted average cost', function () {
    $context = inventoryContext();
    $product = inventoryProduct($context['company']);
    app(RegisterOpeningStock::class)->handle($context['membership'], $context['warehouse'], $product, 10, 10);

    app(RecordManualInbound::class)->handle($context['membership'], $context['warehouse'], $product, 10, 20);

    $balance = StockBalance::query()->where('product_id', $product->getKey())->firstOrFail();
    expect($balance->quantity)->toBe('20.000000')
        ->and($balance->inventory_value_base)->toBe('300.0000')
        ->and($balance->average_unit_cost_base)->toBe('15.0000')
        ->and($balance->last_inbound_unit_cost_base)->toBe('20.0000');
});

test('an outbound movement preserves weighted average cost', function () {
    $context = inventoryContext();
    $product = inventoryProduct($context['company']);
    app(RegisterOpeningStock::class)->handle($context['membership'], $context['warehouse'], $product, 10, 12);

    $movement = app(RecordManualOutbound::class)->handle(
        $context['membership'], $context['warehouse'], $product, 4,
    );

    $balance = StockBalance::query()->where('product_id', $product->getKey())->firstOrFail();
    expect($balance->quantity)->toBe('6.000000')
        ->and($balance->inventory_value_base)->toBe('72.0000')
        ->and($balance->average_unit_cost_base)->toBe('12.0000')
        ->and($movement->lines->firstOrFail()->unit_cost_base)->toBe('12.0000');
});

test('negative stock respects company configuration and fallback cost', function () {
    $blocked = inventoryContext();
    $blockedProduct = inventoryProduct($blocked['company'], ['fallback_unit_cost_base' => 8]);

    expect(fn () => app(RecordManualOutbound::class)->handle(
        $blocked['membership'], $blocked['warehouse'], $blockedProduct, 1,
    ))->toThrow(DomainException::class, 'Negative stock is disabled');

    $allowed = inventoryContext(true);
    $allowedProduct = inventoryProduct($allowed['company'], ['fallback_unit_cost_base' => 8]);
    app(RecordManualOutbound::class)->handle(
        $allowed['membership'], $allowed['warehouse'], $allowedProduct, 2,
    );

    $balance = StockBalance::query()->where('product_id', $allowedProduct->getKey())->firstOrFail();
    expect($balance->quantity)->toBe('-2.000000')
        ->and($balance->inventory_value_base)->toBe('-16.0000')
        ->and($balance->average_unit_cost_base)->toBe('8.0000');
});

test('negative stock is blocked without an average or fallback cost', function () {
    $context = inventoryContext(true);
    $product = inventoryProduct($context['company'], ['fallback_unit_cost_base' => null]);

    expect(fn () => app(RecordManualOutbound::class)->handle(
        $context['membership'], $context['warehouse'], $product, 1,
    ))->toThrow(DomainException::class, 'known average or fallback');

    expect(StockBalance::query()->count())->toBe(0);
});

test('an inbound movement reconciles negative stock and records cost variance', function () {
    $context = inventoryContext(true);
    $product = inventoryProduct($context['company'], ['fallback_unit_cost_base' => 8]);
    app(RecordManualOutbound::class)->handle(
        $context['membership'], $context['warehouse'], $product, 2,
    );

    $movement = app(RecordManualInbound::class)->handle(
        $context['membership'], $context['warehouse'], $product, 3, 10,
    );

    $balance = StockBalance::query()->where('product_id', $product->getKey())->firstOrFail();
    expect($balance->quantity)->toBe('1.000000')
        ->and($balance->inventory_value_base)->toBe('10.0000')
        ->and($balance->average_unit_cost_base)->toBe('10.0000')
        ->and($movement->lines->firstOrFail()->cost_variance_base)->toBe('4.0000');
});

test('a company cannot use another company product or warehouse', function () {
    $first = inventoryContext();
    $second = inventoryContext();
    $firstProduct = inventoryProduct($first['company']);
    $secondProduct = inventoryProduct($second['company']);

    expect(fn () => app(RegisterOpeningStock::class)->handle(
        $first['membership'], $second['warehouse'], $firstProduct, 1, 10,
    ))->toThrow(DomainException::class, 'same company and warehouse')
        ->and(fn () => app(RegisterOpeningStock::class)->handle(
            $first['membership'], $first['warehouse'], $secondProduct, 1, 10,
        ))->toThrow(DomainException::class, 'movement company');
});

test('a reversal creates a compensating movement without changing the original', function () {
    $context = inventoryContext();
    $product = inventoryProduct($context['company']);
    $original = app(RegisterOpeningStock::class)->handle(
        $context['membership'], $context['warehouse'], $product, 5, 10,
    );
    $originalAttributes = $original->getAttributes();

    $reversal = app(ReverseStockMovement::class)->handle(
        $context['membership'], $original, 'Opening correction',
    );

    $balance = StockBalance::query()->where('product_id', $product->getKey())->firstOrFail();
    expect($reversal->reversal_of_movement_id)->toBe($original->getKey())
        ->and($reversal->lines->firstOrFail()->quantity)->toBe('-5.000000')
        ->and($balance->quantity)->toBe('0.000000')
        ->and($balance->inventory_value_base)->toBe('0.0000')
        ->and($original->refresh()->getAttributes())->toEqual($originalAttributes);

    expect(fn () => $original->update(['reason' => 'Changed']))
        ->toThrow(DomainException::class, 'immutable')
        ->and(fn () => $original->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');
});
