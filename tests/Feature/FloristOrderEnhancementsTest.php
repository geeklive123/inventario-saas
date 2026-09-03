<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Expenses\CreateExpense;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\UpdateSaleOrderStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Enums\SaleOrderStatus;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\ExpenseCategory;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array<string, mixed> */
function orderEnhancementContext(string $name = 'Florería mejoras'): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => $name,
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $user->getKey())->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory, ModuleCode::Sales, ModuleCode::Finance] as $code) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', $code)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);
    $rose = Product::factory()->create([
        'company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Rosa',
        'sku' => fake()->unique()->bothify('ROSA-###'), 'is_sellable' => false, 'fallback_unit_cost_base' => 5,
    ]);
    $paper = Product::factory()->create([
        'company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Papel',
        'sku' => fake()->unique()->bothify('PAP-###'), 'is_sellable' => false, 'fallback_unit_cost_base' => 2,
    ]);
    app(RegisterOpeningStock::class)->handle($membership, $warehouse, $rose, 100, 5);
    app(RegisterOpeningStock::class)->handle($membership, $warehouse, $paper, 100, 2);
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Ramo Amor',
        'sku' => fake()->unique()->bothify('RAMO-###'), 'is_sellable' => true, 'sale_price_base' => 80,
    ]);
    $recipe = app(CreateProductRecipe::class)->handle(
        $membership, $bouquet, 1, [['product' => $rose, 'quantity' => 2]],
    );

    return compact('user', 'company', 'membership', 'branch', 'warehouse', 'unit', 'rose', 'paper', 'bouquet', 'recipe') + [
        'cash' => PaymentMethod::query()->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())->where('code', 'cash')->firstOrFail(),
    ];
}

test('a reserved sale requires and preserves its delivery date when its order status changes', function () {
    $context = orderEnhancementContext();

    expect(fn () => app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [['product' => $context['bouquet'], 'quantity' => 1]], [],
    ))->toThrow(DomainException::class, 'fecha y hora de entrega');

    $deliveryAt = now()->addDay()->startOfHour();
    $sale = app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [['product' => $context['bouquet'], 'quantity' => 1]], [], deliveryAt: $deliveryAt,
    );
    app(UpdateSaleOrderStatus::class)->handle($context['membership'], $sale, SaleOrderStatus::Preparing);

    expect($sale->refresh()->delivery_at?->equalTo($deliveryAt))->toBeTrue()
        ->and($sale->delivery_updated_by_membership_id)->toBe($context['membership']->getKey());
});

test('a non-reserved sale does not require a delivery date', function () {
    $context = orderEnhancementContext();
    $sale = app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [['product' => $context['bouquet'], 'quantity' => 1]], [],
        orderStatus: SaleOrderStatus::Preparing,
    );

    expect($sale->delivery_at)->toBeNull()
        ->and($sale->order_status)->toBe(SaleOrderStatus::Preparing);
});

test('sale customizations multiply price and consumption once and preserve historical snapshots', function () {
    $context = orderEnhancementContext();
    $sale = app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [[
            'product' => $context['bouquet'], 'quantity' => 3,
            'customizations' => [[
                'product' => $context['paper'],
                'quantity' => 2,
                'unit_price_base' => '10.50',
                'note' => 'Papel color rosado',
            ]],
        ]],
        [],
        deliveryAt: now()->addDay(),
    );
    $components = $sale->items->sole()->components->keyBy('product_id');
    $stockLines = $sale->stockMovementLinks->sole()->stockMovement->lines->keyBy('product_id');

    expect($components[$context['rose']->getKey()]->quantity_consumed)->toBe('6.000000')
        ->and($components[$context['paper']->getKey()]->customization_quantity)->toBe('2.000000')
        ->and($components[$context['paper']->getKey()]->customization_quantity_consumed)->toBe('6.000000')
        ->and($components[$context['paper']->getKey()]->customization_unit_price_base)->toBe('10.5000')
        ->and($components[$context['paper']->getKey()]->customization_total_price_base)->toBe('63.0000')
        ->and($components[$context['paper']->getKey()]->customization_note)->toBe('Papel color rosado')
        ->and($components[$context['paper']->getKey()]->customization_total_cost_base)->toBe('12.0000')
        ->and($sale->items->sole()->subtotal_base)->toBe('303.0000')
        ->and($sale->total_base)->toBe('303.0000')
        ->and($sale->total_cost_base)->toBe('42.0000')
        ->and($sale->gross_margin_base)->toBe('261.0000')
        ->and($context['recipe']->items()->count())->toBe(1)
        ->and($stockLines)->toHaveCount(2)
        ->and($stockLines[$context['paper']->getKey()]->quantity)->toBe('-6.000000')
        ->and(StockBalance::query()->where('product_id', $context['rose']->getKey())->value('quantity'))->toBe('94.000000')
        ->and(StockBalance::query()->where('product_id', $context['paper']->getKey())->value('quantity'))->toBe('94.000000');

    $context['paper']->update(['name' => 'Papel renombrado', 'fallback_unit_cost_base' => 20]);
    $historicalCustomization = $sale->items()->sole()->components()->where('product_id', $context['paper']->getKey())->sole();

    expect($historicalCustomization->product_name)->toBe('Papel')
        ->and($historicalCustomization->customization_unit_price_base)->toBe('10.5000')
        ->and($historicalCustomization->customization_note)->toBe('Papel color rosado');

    app(CurrentCompany::class)->set($context['membership']);
    session()->put('current_membership_id', $context['membership']->getKey());
    Livewire::actingAs($context['user'])
        ->test('pages::sales.show', ['saleId' => $sale->getKey()])
        ->assertSee('Papel')
        ->assertDontSee('Papel renombrado')
        ->assertSee('Bs 10,50')
        ->assertSee('Papel color rosado');
});

test('increasing an existing recipe component is snapshotted without changing the recipe', function () {
    $context = orderEnhancementContext();
    $sale = app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [[
            'product' => $context['bouquet'], 'quantity' => 2,
            'customizations' => [['product' => $context['rose'], 'quantity' => 1, 'unit_price_base' => 5]],
        ]],
        [],
        deliveryAt: now()->addDay(),
    );
    $roseSnapshot = $sale->items->sole()->components->sole();

    expect($roseSnapshot->recipe_quantity)->toBe('2.000000')
        ->and($roseSnapshot->customization_quantity)->toBe('1.000000')
        ->and($roseSnapshot->quantity_consumed)->toBe('6.000000')
        ->and($context['recipe']->items()->sole()->quantity)->toBe('2.000000');
});

test('customization price and note are validated by the sale action', function () {
    $context = orderEnhancementContext();

    expect(fn () => app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [[
            'product' => $context['bouquet'], 'quantity' => 1,
            'customizations' => [['product' => $context['paper'], 'quantity' => 1, 'unit_price_base' => -1]],
        ]],
        [],
        deliveryAt: now()->addDay(),
    ))->toThrow(DomainException::class, 'precio no negativo');

    expect(fn () => app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [[
            'product' => $context['bouquet'], 'quantity' => 1,
            'customizations' => [[
                'product' => $context['paper'], 'quantity' => 1, 'unit_price_base' => 1, 'note' => str_repeat('a', 501),
            ]],
        ]],
        [],
        deliveryAt: now()->addDay(),
    ))->toThrow(DomainException::class, 'no puede superar 500 caracteres');
});

test('sales interface captures and totals customization price and note', function () {
    $context = orderEnhancementContext();
    app(CurrentCompany::class)->set($context['membership']);
    session()->put('current_membership_id', $context['membership']->getKey());

    Livewire::actingAs($context['user'])
        ->test('pages::sales.index')
        ->call('openSale')
        ->set('saleLines.0.product_id', $context['bouquet']->getKey())
        ->set('saleLines.0.quantity', '2')
        ->call('addCustomization', 0)
        ->set('saleLines.0.customizations.0.product_id', $context['paper']->getKey())
        ->set('saleLines.0.customizations.0.quantity', '1')
        ->set('saleLines.0.customizations.0.unit_price_base', '10')
        ->set('saleLines.0.customizations.0.note', 'Papel rojo')
        ->assertSee('Precio unitario (Bs)')
        ->assertSee('Importe que se cobrará al cliente por cada unidad adicional.')
        ->assertSee('Bs 180,00')
        ->call('confirmSale')
        ->assertHasNoErrors();

    $sale = Sale::query()->sole();

    expect($sale->total_base)->toBe('180.0000')
        ->and($sale->items()->sole()->components()->where('product_id', $context['paper']->getKey())->sole()->customization_note)
        ->toBe('Papel rojo');
});

test('a foreign customization cannot alter inventory', function () {
    $first = orderEnhancementContext('Primera');
    $second = orderEnhancementContext('Segunda');

    expect(fn () => app(ConfirmSale::class)->handle(
        $first['membership'], $first['branch'], $first['warehouse'],
        [[
            'product' => $first['bouquet'], 'quantity' => 1,
            'customizations' => [['product' => $second['paper'], 'quantity' => 1]],
        ]],
        [],
        deliveryAt: now()->addDay(),
    ))->toThrow(DomainException::class, 'pertenecer a la empresa');

    expect(StockBalance::query()->withoutGlobalScope('company')
        ->where('company_id', $first['company']->getKey())->where('product_id', $first['rose']->getKey())->value('quantity'))
        ->toBe('100.000000');
});

test('operational expenses use the safe legacy default without asking for classification or sale', function () {
    $context = orderEnhancementContext();
    $category = ExpenseCategory::query()->withoutGlobalScope('company')
        ->where('company_id', $context['company']->getKey())->firstOrFail();
    $expense = app(CreateExpense::class)->handle($context['membership'], $category, 50, 'Internet');

    expect($expense->getRawOriginal('type'))->toBe('indirect')
        ->and($expense->sale_id)->toBeNull();
});

test('expense action no longer accepts operational type or sale parameters', function () {
    $parameterNames = collect((new ReflectionMethod(CreateExpense::class, 'handle'))->getParameters())
        ->map(fn (ReflectionParameter $parameter): string => $parameter->getName());

    expect($parameterNames)->not->toContain('type', 'sale');
});

test('recipe editor starts from the active recipe components and quantities', function () {
    $context = orderEnhancementContext();
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['user'])
        ->test('pages::catalog.recipes')
        ->set('selectedProductId', $context['bouquet']->getKey())
        ->call('openDraft')
        ->assertSet('yieldQuantity', '1.000000')
        ->assertSet('components.0.component_id', (string) $context['rose']->getKey())
        ->assertSet('components.0.quantity', '2.000000');
});

test('saving an edited recipe creates a new active version and preserves the previous one', function () {
    $context = orderEnhancementContext();
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['user'])
        ->test('pages::catalog.recipes')
        ->set('selectedProductId', $context['bouquet']->getKey())
        ->call('openDraft')
        ->set('components.0.quantity', '4')
        ->call('activateDraft')
        ->assertHasNoErrors();

    $recipes = $context['bouquet']->recipes()->orderBy('version')->get();
    expect($recipes)->toHaveCount(2)
        ->and($recipes->first()->active_slot)->toBeNull()
        ->and($recipes->last()->active_slot)->toBe(1)
        ->and($recipes->last()->items()->sole()->quantity)->toBe('4.000000');
});

test('a worker without sales permission cannot update delivery information', function () {
    $context = orderEnhancementContext();
    $sale = app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [['product' => $context['bouquet'], 'quantity' => 1]], [], deliveryAt: now()->addDay(),
    );
    $worker = Membership::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => User::factory()->create()->getKey(),
        'is_owner' => false,
    ]);
    $worker->roles()->attach(Role::factory()->create(['company_id' => $context['company']->getKey()]), [
        'company_id' => $context['company']->getKey(),
    ]);

    expect(fn () => app(UpdateSaleOrderStatus::class)->handle(
        $worker, $sale, SaleOrderStatus::Reserved, now()->addDays(2),
    ))->toThrow(DomainException::class, 'no puede cambiar');
});
