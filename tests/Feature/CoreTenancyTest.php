<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Memberships\ChangeMembershipStatus;
use App\Actions\Roles\AssignRole;
use App\Enums\MembershipStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Authorization\CompanyAccess;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function createCompanyFor(User $owner, string $name): Company
{
    $currency = Currency::query()->where('code', 'BOB')->firstOrFail();

    return app(CreateCompany::class)->handle($owner, [
        'name' => $name,
        'base_currency_id' => $currency->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
}

function membershipFor(User $user, Company $company): Membership
{
    return Membership::query()
        ->withoutGlobalScope('company')
        ->where('user_id', $user->getKey())
        ->where('company_id', $company->getKey())
        ->firstOrFail();
}

test('a company cannot access another company data', function () {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstCompany = createCompanyFor($firstOwner, 'First Company');
    $secondCompany = createCompanyFor($secondOwner, 'Second Company');

    $firstBranch = Branch::factory()->create(['company_id' => $firstCompany->getKey()]);
    $firstWarehouse = Warehouse::factory()->forBranch($firstBranch)->create();
    $secondBranch = Branch::factory()->create(['company_id' => $secondCompany->getKey()]);
    $secondWarehouse = Warehouse::factory()->forBranch($secondBranch)->create();

    app(CurrentCompany::class)->set(membershipFor($firstOwner, $firstCompany));

    expect(Warehouse::query()->find($firstWarehouse->getKey()))->not->toBeNull()
        ->and(Warehouse::query()->find($secondWarehouse->getKey()))->toBeNull()
        ->and(Gate::forUser($firstOwner)->allows('view', $secondWarehouse))->toBeFalse();
});

test('a user can belong to multiple companies', function () {
    $user = User::factory()->create();

    createCompanyFor($user, 'First Company');
    createCompanyFor($user, 'Second Company');

    expect($user->memberships()->count())->toBe(2)
        ->and($user->memberships()->where('is_owner', true)->count())->toBe(2);
});

test('an active owner has total access without roles', function () {
    $owner = User::factory()->create();
    $company = createCompanyFor($owner, 'Owner Company');

    expect(membershipFor($owner, $company)->roles)->toBeEmpty()
        ->and(Gate::forUser($owner)->allows('update', $company))->toBeTrue()
        ->and(app(CompanyAccess::class)->allows($owner, $company, 'core.roles.assign'))->toBeTrue();
});

test('a member without a permission cannot access the action', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = createCompanyFor($owner, 'Restricted Company');

    Membership::factory()->create([
        'company_id' => $company->getKey(),
        'user_id' => $member->getKey(),
        'status' => MembershipStatus::Active,
    ]);

    expect(Gate::forUser($member)->allows('update', $company))->toBeFalse()
        ->and(app(CompanyAccess::class)->allows($member, $company, 'core.roles.assign'))->toBeFalse();
});

test('a role grants its permissions to a membership', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = createCompanyFor($owner, 'Role Company');
    $ownerMembership = membershipFor($owner, $company);
    $memberMembership = Membership::factory()->create([
        'company_id' => $company->getKey(),
        'user_id' => $member->getKey(),
        'status' => MembershipStatus::Active,
    ]);
    $role = Role::factory()->create(['company_id' => $company->getKey()]);
    $permission = Permission::query()->where('code', 'core.companies.update')->firstOrFail();

    RolePermission::query()->create([
        'company_id' => $company->getKey(),
        'role_id' => $role->getKey(),
        'permission_id' => $permission->getKey(),
    ]);

    app(AssignRole::class)->handle($ownerMembership, $memberMembership, $role);

    expect(Gate::forUser($member)->allows('update', $company))->toBeTrue();
});

test('a disabled module blocks mutations but allows authorized historical reads', function () {
    $owner = User::factory()->create();
    $company = createCompanyFor($owner, 'Modules Company');
    $membership = membershipFor($owner, $company);

    Route::middleware(['web', 'auth', 'company', 'module:sales', 'permission:sales.manage'])
        ->post('/_tests/company-module', fn () => response()->noContent());
    Route::middleware(['web', 'auth', 'company', 'module:sales', 'permission:sales.view'])
        ->get('/_tests/company-module', fn () => response()->noContent());

    $this->actingAs($owner)
        ->withSession(['current_membership_id' => $membership->getKey()])
        ->post('/_tests/company-module')
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession(['current_membership_id' => $membership->getKey()])
        ->get('/_tests/company-module')
        ->assertNoContent();
});

test('the last active owner cannot be suspended', function () {
    $owner = User::factory()->create();
    $company = createCompanyFor($owner, 'Protected Company');
    $ownerMembership = membershipFor($owner, $company);

    expect(fn () => app(ChangeMembershipStatus::class)->handle(
        $ownerMembership,
        $ownerMembership,
        MembershipStatus::Suspended,
    ))->toThrow(DomainException::class, 'at least one active owner');

    expect($ownerMembership->refresh()->status)->toBe(MembershipStatus::Active);
});
