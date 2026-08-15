<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use DomainException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/**
 * @return array{user: User, company: Company, membership: Membership, warehouse: Warehouse, product: Product}
 */
function inventoryAuthorizationContext(bool $inventoryEnabled): array
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

    if ($inventoryEnabled) {
        app(SetModuleStatus::class)->handle(
            $membership,
            Module::query()->where('code', ModuleCode::Inventory)->firstOrFail(),
            CompanyModuleStatus::Enabled,
        );
    }

    $branch = Branch::factory()->create(['company_id' => $company->getKey()]);
    $warehouse = Warehouse::factory()->forBranch($branch)->create();
    $unit = Unit::factory()->create(['company_id' => $company->getKey()]);
    $product = Product::factory()->create([
        'company_id' => $company->getKey(),
        'unit_id' => $unit->getKey(),
    ]);

    return compact('user', 'company', 'membership', 'warehouse', 'product');
}

test('an owner with inventory enabled can register inventory operations', function () {
    $context = inventoryAuthorizationContext(true);

    $movement = app(RegisterOpeningStock::class)->handle(
        $context['membership'],
        $context['warehouse'],
        $context['product'],
        '10',
        '5',
        'Apertura autorizada',
    );

    $this->actingAs($context['user'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('inventory.stock'))
        ->assertSuccessful()
        ->assertSee('Cargar existencia inicial')
        ->assertDontSee('Modo histórico');

    expect($movement->company_id)->toBe($context['company']->getKey())
        ->and($movement->created_by_membership_id)->toBe($context['membership']->getKey());
});

test('an owner with inventory disabled can only read inventory history', function () {
    $context = inventoryAuthorizationContext(false);

    $this->actingAs($context['user'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('inventory.stock'))
        ->assertSuccessful()
        ->assertSee('Modo histórico')
        ->assertDontSee('Cargar existencia inicial');

    expect(fn () => app(RegisterOpeningStock::class)->handle(
        $context['membership'],
        $context['warehouse'],
        $context['product'],
        '10',
        '5',
    ))->toThrow(DomainException::class, 'not authorized');
});

test('a member without inventory permission cannot register operations', function () {
    $context = inventoryAuthorizationContext(true);
    $member = User::factory()->create();
    $membership = Membership::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $member->getKey(),
        'status' => MembershipStatus::Active,
        'is_owner' => false,
    ]);

    $this->actingAs($member)
        ->withSession(['current_membership_id' => $membership->getKey()])
        ->get(route('inventory.stock'))
        ->assertForbidden();

    expect(fn () => app(RegisterOpeningStock::class)->handle(
        $membership,
        $context['warehouse'],
        $context['product'],
        '10',
        '5',
    ))->toThrow(DomainException::class, 'not authorized');
});

test('inventory operations cannot cross company boundaries', function () {
    $first = inventoryAuthorizationContext(true);
    $second = inventoryAuthorizationContext(true);

    expect(fn () => app(RegisterOpeningStock::class)->handle(
        $first['membership'],
        $second['warehouse'],
        $first['product'],
        '10',
        '5',
    ))->toThrow(DomainException::class, 'same company and warehouse')
        ->and(fn () => app(RegisterOpeningStock::class)->handle(
            $first['membership'],
            $first['warehouse'],
            $second['product'],
            '10',
            '5',
        ))->toThrow(DomainException::class, 'movement company')
        ->and(StockMovement::query()->count())->toBe(0);
});
