<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Modules\SetModuleStatus;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\ConfirmSaleOnce;
use App\Actions\Sales\VoidSale;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Enums\SaleOrderStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleExtra;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\PotentialDuplicateSaleFinder;
use App\Support\CustomerPhoneNormalizer;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Cache::flush();
});

/** @return array{user: User, company: Company, membership: Membership, branch: Branch, warehouse: Warehouse, firstBouquet: Product, secondBouquet: Product} */
function duplicateSalesFixture(): array
{
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->handle($user, [
        'name' => fake()->unique()->company(),
        'base_currency_id' => Currency::query()->where('code', 'BOB')->firstOrFail()->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
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
    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);
    $supply = Product::factory()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
        'name' => 'Rosa para duplicados',
        'is_sellable' => false,
    ]);
    app(RegisterOpeningStock::class)->handle($membership, $warehouse, $supply, 1000, 5);

    $firstBouquet = Product::factory()->composed()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
        'name' => 'Ramo Primavera',
        'is_sellable' => true,
        'sale_price_base' => 50,
    ]);
    $secondBouquet = Product::factory()->composed()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
        'name' => 'Ramo Otoño',
        'is_sellable' => true,
        'sale_price_base' => 70,
    ]);

    foreach ([$firstBouquet, $secondBouquet] as $bouquet) {
        app(CreateProductRecipe::class)->handle($membership, $bouquet, 1, [
            ['product' => $supply, 'quantity' => 1],
        ]);
    }

    app(CurrentCompany::class)->set($membership);

    return compact('user', 'company', 'membership', 'branch', 'warehouse', 'firstBouquet', 'secondBouquet');
}

/**
 * @param  array{membership: Membership, branch: Branch, warehouse: Warehouse}  $context
 * @param  list<Product>  $products
 * @param  list<array{extra: SaleExtra, quantity: int, unit_price_base: string}>  $extras
 */
function createDuplicateCandidate(array $context, string $customer, CarbonImmutable $deliveryAt, array $products, array $extras = []): Sale
{
    return app(ConfirmSale::class)->handle(
        $context['membership'],
        $context['branch'],
        $context['warehouse'],
        collect($products)->map(fn (Product $product): array => ['product' => $product, 'quantity' => 1])->all(),
        [],
        $customer,
        null,
        $extras,
        deliveryAt: $deliveryAt,
    );
}

/** @param list<int> $productIds */
function findPotentialDuplicate(array $context, string $customer, CarbonImmutable $deliveryAt, array $productIds): ?array
{
    return app(PotentialDuplicateSaleFinder::class)->find(
        $context['company'],
        $customer,
        $deliveryAt->subDay(),
        $deliveryAt,
        $productIds,
    );
}

test('same phone relevant date and bouquet shows a warning before creating the sale', function () {
    $context = duplicateSalesFixture();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    $existingSale = createDuplicateCandidate($context, '+591 62722154', $deliveryAt, [$context['firstBouquet']]);

    $component = Livewire::actingAs($context['user'])
        ->test('pages::sales.index')
        ->call('openSale')
        ->set('customerName', '62722154 ')
        ->set('branchId', $context['branch']->getKey())
        ->set('warehouseId', $context['warehouse']->getKey())
        ->set('deliveryAt', $deliveryAt->setTimezone($context['company']->timezone)->format('Y-m-d\TH:i'))
        ->set('saleLines', [['product_id' => $context['firstBouquet']->getKey(), 'quantity' => '1']])
        ->call('confirmSale')
        ->assertHasNoErrors()
        ->assertSet('potentialDuplicateSale.number', $existingSale->number);

    expect(Sale::query()->count())->toBe(1);

    $component->call('continuePotentialDuplicateSale')->assertHasNoErrors();

    expect(Sale::query()->count())->toBe(2);
});

test('same phone and date with a different bouquet does not warn', function () {
    $context = duplicateSalesFixture();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    createDuplicateCandidate($context, '62722154', $deliveryAt, [$context['firstBouquet']]);

    expect(findPotentialDuplicate($context, '62722154', $deliveryAt, [$context['secondBouquet']->getKey()]))->toBeNull();
});

test('same phone and bouquet on a different relevant date does not warn', function () {
    $context = duplicateSalesFixture();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    createDuplicateCandidate($context, '62722154', $deliveryAt, [$context['firstBouquet']]);

    expect(findPotentialDuplicate($context, '62722154', $deliveryAt->addDay(), [$context['firstBouquet']->getKey()]))->toBeNull();
});

test('different phone with the same date and bouquet does not warn', function () {
    $context = duplicateSalesFixture();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    createDuplicateCandidate($context, '62722154', $deliveryAt, [$context['firstBouquet']]);

    expect(findPotentialDuplicate($context, '71234567', $deliveryAt, [$context['firstBouquet']->getKey()]))->toBeNull();
});

test('voided or cancelled sales are excluded from duplicate detection', function () {
    $context = duplicateSalesFixture();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    $sale = createDuplicateCandidate($context, '62722154', $deliveryAt, [$context['firstBouquet']]);
    app(VoidSale::class)->handle($context['membership'], $sale, 'Registro anulado para la prueba');

    expect(findPotentialDuplicate($context, '62722154', $deliveryAt, [$context['firstBouquet']->getKey()]))->toBeNull();
});

test('a sale from another company is never exposed as a duplicate', function () {
    $firstCompany = duplicateSalesFixture();
    $secondCompany = duplicateSalesFixture();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $secondCompany['company']->timezone)->utc();
    createDuplicateCandidate($secondCompany, '62722154', $deliveryAt, [$secondCompany['firstBouquet']]);

    expect(findPotentialDuplicate($firstCompany, '+591 62722154', $deliveryAt, [$secondCompany['firstBouquet']->getKey()]))->toBeNull();
});

test('reasonable bolivian mobile formats normalize to the same value', function (string $phone) {
    expect(app(CustomerPhoneNormalizer::class)->normalize($phone))->toBe('62722154');
})->with([
    'plain' => '62722154',
    'trailing space' => '62722154 ',
    'leading space' => ' 62722154',
    'country prefix and space' => '+591 62722154',
    'country prefix and hyphen' => '+591-62722154',
    'customer name containing phone' => 'María 62722154',
]);

test('invalid or ambiguous numbers are not normalized', function (string $phone) {
    expect(app(CustomerPhoneNormalizer::class)->normalize($phone))->toBeNull();
})->with(['123', '591627221549', '12345678', 'teléfono no registrado']);

test('the same form attempt is confirmed only once on a retry', function () {
    $context = duplicateSalesFixture();
    $attemptToken = Str::uuid()->toString();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    $confirm = fn (): Sale => app(ConfirmSaleOnce::class)->handle(
        $attemptToken,
        $context['membership'],
        $context['branch'],
        $context['warehouse'],
        [['product' => $context['firstBouquet'], 'quantity' => 1]],
        [],
        '62722154',
        null,
        [],
        SaleOrderStatus::Reserved,
        $deliveryAt,
    );

    $first = $confirm();
    $retry = $confirm();

    expect($retry->is($first))->toBeTrue()
        ->and(Sale::query()->count())->toBe(1);

    $this->actingAs($context['user'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('sales.index'))
        ->assertSuccessful()
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSee('Guardando...');
});

test('multiple sale items match by product id while extras are ignored', function () {
    $context = duplicateSalesFixture();
    $deliveryAt = CarbonImmutable::parse('2026-10-10 09:00', $context['company']->timezone)->utc();
    $extra = SaleExtra::factory()->create([
        'company_id' => $context['company']->getKey(),
        'name' => $context['firstBouquet']->name,
        'default_price_base' => 5,
    ]);
    createDuplicateCandidate(
        $context,
        '62722154',
        $deliveryAt,
        [$context['secondBouquet']],
        [['extra' => $extra, 'quantity' => 1, 'unit_price_base' => '5']],
    );

    expect(findPotentialDuplicate($context, '62722154', $deliveryAt, [$context['firstBouquet']->getKey()]))->toBeNull();

    createDuplicateCandidate($context, '62722154', $deliveryAt, [$context['secondBouquet'], $context['firstBouquet']]);
    $match = findPotentialDuplicate($context, '62722154', $deliveryAt, [$context['firstBouquet']->getKey()]);

    expect($match)->not->toBeNull()
        ->and($match['products'])->toBe([$context['firstBouquet']->name]);
});
