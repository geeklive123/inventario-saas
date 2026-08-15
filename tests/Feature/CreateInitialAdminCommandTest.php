<?php

use App\Enums\CompanyModuleStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Hash;

test('command creates the complete initial administrator structure', function () {
    $this->artisan('app:create-initial-admin', [
        '--name' => 'Administradora Inicial',
        '--email' => 'ADMIN@EXAMPLE.COM',
        '--password' => 'Secure-Password-2026!',
        '--company' => 'Florería Inicial',
    ])->assertSuccessful();

    $user = User::query()->sole();
    $company = Company::query()->sole();
    $membership = Membership::query()->withoutGlobalScope('company')->sole();
    $administrator = $membership->roles()->sole();

    expect($user->name)->toBe('Administradora Inicial')
        ->and($user->email)->toBe('admin@example.com')
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->password)->not->toBe('Secure-Password-2026!')
        ->and(Hash::check('Secure-Password-2026!', $user->password))->toBeTrue()
        ->and($company->name)->toBe('Florería Inicial')
        ->and($company->baseCurrency->code)->toBe('BOB')
        ->and($membership->user_id)->toBe($user->getKey())
        ->and($membership->company_id)->toBe($company->getKey())
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->is_owner)->toBeTrue()
        ->and($administrator->name)->toBe('Administrador')
        ->and($administrator->permissions()->count())->toBe(Permission::query()->where('is_active', true)->count())
        ->and($company->companyModules()->whereHas('module', fn ($query) => $query->where('code', ModuleCode::Core))->sole()->status)->toBe(CompanyModuleStatus::Enabled)
        ->and($company->paymentMethods()->where('is_active', true)->count())->toBe(3)
        ->and(Branch::query()->count())->toBe(0)
        ->and(Warehouse::query()->count())->toBe(0);
});

test('interactive command keeps the password hidden and requires confirmation', function () {
    $this->artisan('app:create-initial-admin')
        ->expectsQuestion('Contraseña temporal', 'Secure-Password-2026!')
        ->expectsQuestion('Confirmar contraseña temporal', 'Secure-Password-2026!')
        ->expectsQuestion('Nombre completo del administrador', 'Admin Producción')
        ->expectsQuestion('Correo del administrador', 'admin@production.test')
        ->expectsQuestion('Nombre de la empresa', 'Empresa Producción')
        ->assertSuccessful();

    expect(User::query()->sole()->email)->toBe('admin@production.test');
});

test('command refuses a second bootstrap without duplicating business records', function () {
    $arguments = [
        '--name' => 'Primer Admin',
        '--email' => 'first@example.com',
        '--password' => 'Secure-Password-2026!',
        '--company' => 'Primera Empresa',
    ];

    $this->artisan('app:create-initial-admin', $arguments)->assertSuccessful();

    $this->artisan('app:create-initial-admin', [
        '--name' => 'Segundo Admin',
        '--email' => 'second@example.com',
        '--password' => 'Secure-Password-2026!',
        '--company' => 'Segunda Empresa',
    ])->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and(Company::query()->count())->toBe(1)
        ->and(Membership::query()->withoutGlobalScope('company')->count())->toBe(1);
});

test('invalid data does not create partial business records', function () {
    $this->artisan('app:create-initial-admin', [
        '--name' => 'Admin',
        '--email' => 'not-an-email',
        '--password' => 'short',
        '--company' => 'Empresa',
    ])->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0)
        ->and(Membership::query()->withoutGlobalScope('company')->count())->toBe(0);
});
