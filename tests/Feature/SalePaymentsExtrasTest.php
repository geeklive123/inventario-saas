<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Companies\UpdateSalesSettings;
use App\Actions\Expenses\CreateExpense;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\RegisterSalePayment;
use App\Actions\Sales\SaveSaleExtra;
use App\Actions\Sales\UpdateSaleOrderStatus;
use App\Actions\Sales\VoidSale;
use App\Actions\Users\CreateWorker;
use App\Enums\CompanyModuleStatus;
use App\Enums\ExpenseReceiptType;
use App\Enums\ModuleCode;
use App\Enums\PaymentStatus;
use App\Enums\SaleExtraType;
use App\Enums\SaleOrderStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExpenseCategory;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleExtra;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Dashboard\BusinessDashboard;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{owner: User, company: Company, membership: Membership, branch: Branch, warehouse: Warehouse, bouquet: Product, cash: PaymentMethod, qr: PaymentMethod} */
function paymentFeatureContext(string $name = 'Florería pagos'): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => $name,
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->firstOrFail();

    foreach ([ModuleCode::Catalog, ModuleCode::Inventory, ModuleCode::Sales, ModuleCode::Finance] as $moduleCode) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', $moduleCode)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create(['company_id' => $company->getKey(), 'code' => fake()->unique()->bothify('UND-###')]);
    $supply = Product::factory()->create([
        'company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Rosa',
        'sku' => fake()->unique()->bothify('ROSA-###'), 'is_sellable' => false, 'fallback_unit_cost_base' => 5,
    ]);
    app(RegisterOpeningStock::class)->handle($membership, $warehouse, $supply, 100, 5);
    $bouquet = Product::factory()->composed()->create([
        'company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Ramo prueba',
        'sku' => fake()->unique()->bothify('RAMO-###'), 'is_sellable' => true, 'sale_price_base' => 100,
    ]);
    app(CreateProductRecipe::class)->handle($membership, $bouquet, 1, [['product' => $supply, 'quantity' => 1]]);
    $methods = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $company->getKey())->get()->keyBy('code');

    return [
        'owner' => $owner, 'company' => $company, 'membership' => $membership,
        'branch' => $branch, 'warehouse' => $warehouse, 'bouquet' => $bouquet,
        'cash' => $methods->get('cash'), 'qr' => $methods->get('qr'),
    ];
}

/** @param array<int, array{payment_method: PaymentMethod, amount_base: int|float|string}> $payments */
function paymentFeatureSale(array $context, array $payments = [], array $extras = [], ?CarbonImmutable $occurredAt = null): Sale
{
    return app(ConfirmSale::class)->handle(
        $context['membership'], $context['branch'], $context['warehouse'],
        [['product' => $context['bouquet'], 'quantity' => 1]], $payments, null, $occurredAt, $extras,
        deliveryAt: now()->addDay(),
    );
}

test('a sale may be completely paid when confirmed', function () {
    $context = paymentFeatureContext();
    $sale = paymentFeatureSale($context, [['payment_method' => $context['cash'], 'amount_base' => 100]]);

    expect($sale->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sale->paid_total_base)->toBe('100.0000')
        ->and($sale->balance_due_base)->toBe('0.0000');
});

test('a sale may receive a partial initial payment', function () {
    $context = paymentFeatureContext();
    $sale = paymentFeatureSale($context, [['payment_method' => $context['cash'], 'amount_base' => 40]]);

    expect($sale->payment_status)->toBe(PaymentStatus::Partial)
        ->and($sale->paid_total_base)->toBe('40.0000')
        ->and($sale->balance_due_base)->toBe('60.0000');
});

test('a sale may be confirmed without an initial payment', function () {
    $sale = paymentFeatureSale(paymentFeatureContext());

    expect($sale->payment_status)->toBe(PaymentStatus::Pending)
        ->and($sale->payments)->toHaveCount(0)
        ->and($sale->balance_due_base)->toBe('100.0000');
});

test('a later payment completes the same partial sale', function () {
    $context = paymentFeatureContext();
    $sale = paymentFeatureSale($context, [['payment_method' => $context['cash'], 'amount_base' => 40]]);

    app(RegisterSalePayment::class)->handle($context['membership'], $sale, $context['qr'], 60);

    expect($sale->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sale->payments()->count())->toBe(2)
        ->and(Sale::query()->count())->toBe(1);
});

test('a payment cannot exceed the locked outstanding balance', function () {
    $context = paymentFeatureContext();
    $sale = paymentFeatureSale($context, [['payment_method' => $context['cash'], 'amount_base' => 80]]);

    expect(fn () => app(RegisterSalePayment::class)->handle($context['membership'], $sale, $context['qr'], 21))
        ->toThrow(DomainException::class, 'superar el saldo');
    expect($sale->refresh()->paid_total_base)->toBe('80.0000')->and($sale->payments()->count())->toBe(1);
});

test('a sale supports split payments using multiple methods', function () {
    $context = paymentFeatureContext();
    $sale = paymentFeatureSale($context, [
        ['payment_method' => $context['cash'], 'amount_base' => 50],
        ['payment_method' => $context['qr'], 'amount_base' => 50],
    ]);

    expect($sale->payments)->toHaveCount(2)->and($sale->payment_status)->toBe(PaymentStatus::Paid);
});

test('extras are included in the sale total as immutable snapshots', function () {
    $context = paymentFeatureContext();
    $extra = app(SaveSaleExtra::class)->handle($context['membership'], 'Delivery', 20, SaleExtraType::Service);
    $sale = paymentFeatureSale($context, [], [['extra' => $extra, 'quantity' => 2]]);

    expect($sale->subtotal_base)->toBe('100.0000')
        ->and($sale->extras_total_base)->toBe('40.0000')
        ->and($sale->total_base)->toBe('140.0000')
        ->and($sale->extraLines->sole()->extra_name)->toBe('Delivery');
});

test('changing an extra catalog price does not alter a historical sale', function () {
    $context = paymentFeatureContext();
    $extra = app(SaveSaleExtra::class)->handle($context['membership'], 'Globo', 15, SaleExtraType::Product);
    $sale = paymentFeatureSale($context, [], [['extra' => $extra, 'quantity' => 1]]);

    app(SaveSaleExtra::class)->handle($context['membership'], 'Globo', 30, SaleExtraType::Product, $extra);

    expect($sale->extraLines()->sole()->unit_price_base)->toBe('15.0000')
        ->and($sale->refresh()->total_base)->toBe('115.0000');
});

test('an expense with invoice requires its invoice number', function () {
    $context = paymentFeatureContext();
    $category = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();

    expect(fn () => app(CreateExpense::class)->handle(
        $context['membership'], $category, 10, 'Publicidad', receiptType: ExpenseReceiptType::WithInvoice,
    ))->toThrow(DomainException::class, 'número de factura');

    $expense = app(CreateExpense::class)->handle(
        $context['membership'], $category, 10, 'Publicidad', receiptType: ExpenseReceiptType::WithInvoice, invoiceNumber: 'F-123',
    );
    expect($expense->invoice_number)->toBe('F-123');
});

test('an expense without invoice does not require an invoice number', function () {
    $context = paymentFeatureContext();
    $category = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();
    $expense = app(CreateExpense::class)->handle($context['membership'], $category, 10, 'Transporte');

    expect($expense->receipt_type)->toBe(ExpenseReceiptType::WithoutInvoice)
        ->and($expense->invoice_number)->toBeNull();
});

test('dashboard collections use the payment date instead of the sale date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 12:00:00', 'America/La_Paz'));
    $context = paymentFeatureContext();
    app(UpdateSalesSettings::class)->handle($context['membership'], true);
    $sale = paymentFeatureSale($context, [], [], CarbonImmutable::parse('2026-08-16 10:00:00', 'America/La_Paz')->utc());
    app(RegisterSalePayment::class)->handle($context['membership'], $sale, $context['cash'], 30, now());

    $summary = app(BusinessDashboard::class)->summary($context['membership'], 'today');

    expect($summary['sales_booked_base'])->toBe('0.0000')
        ->and($summary['collected_base'])->toBe('30.0000')
        ->and($summary['receivable_base'])->toBe('70.0000')
        ->and($summary['cash_result_base'])->toBe('30.0000');
    CarbonImmutable::setTestNow();
});

test('sale payments and extras cannot cross company boundaries', function () {
    $first = paymentFeatureContext('Primera');
    $second = paymentFeatureContext('Segunda');
    $sale = paymentFeatureSale($first);
    $foreignExtra = SaleExtra::factory()->create(['company_id' => $second['company']->getKey()]);

    expect(fn () => app(RegisterSalePayment::class)->handle($second['membership'], $sale, $second['cash'], 10))
        ->toThrow(DomainException::class, 'no puede registrar')
        ->and(fn () => paymentFeatureSale($first, [], [['extra' => $foreignExtra, 'quantity' => 1]]))
        ->toThrow(DomainException::class, 'pertenecer a la empresa');
});

test('worker permissions control balances payments extras and price overrides', function () {
    $context = paymentFeatureContext();
    $sellerRole = Role::query()->withoutGlobalScope('company')
        ->where('company_id', $context['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $seller = app(CreateWorker::class)->handle($context['membership'], $sellerRole, [
        'name' => 'Vendedora', 'email' => fake()->unique()->safeEmail(), 'password' => 'Temporary-Password-123!',
    ]);
    $sale = paymentFeatureSale($context);

    app(RegisterSalePayment::class)->handle($seller, $sale, $context['cash'], 25);
    expect($sale->refresh()->paid_total_base)->toBe('25.0000');

    $roleWithoutPermissions = Role::factory()->create(['company_id' => $context['company']->getKey()]);
    $worker = app(CreateWorker::class)->handle($context['membership'], $roleWithoutPermissions, [
        'name' => 'Sin acceso', 'email' => fake()->unique()->safeEmail(), 'password' => 'Temporary-Password-123!',
    ]);
    expect(fn () => app(RegisterSalePayment::class)->handle($worker, $sale, $context['cash'], 10))
        ->toThrow(DomainException::class, 'no puede registrar');
});

test('a paid sale cannot be voided before a refund workflow exists', function () {
    $context = paymentFeatureContext();
    $sale = paymentFeatureSale($context, [['payment_method' => $context['cash'], 'amount_base' => 100]]);

    expect(fn () => app(VoidSale::class)->handle($context['membership'], $sale, 'Pedido cancelado'))
        ->toThrow(DomainException::class, 'devolución');
});

test('order status changes independently from payment status', function () {
    $context = paymentFeatureContext();
    $sale = paymentFeatureSale($context);

    app(UpdateSaleOrderStatus::class)->handle($context['membership'], $sale, SaleOrderStatus::Preparing);

    expect($sale->refresh()->order_status)->toBe(SaleOrderStatus::Preparing)
        ->and($sale->payment_status)->toBe(PaymentStatus::Pending);
});
