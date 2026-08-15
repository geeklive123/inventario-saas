<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Memberships\ChangeMembershipStatus;
use App\Actions\Users\CreateWorker;
use App\Actions\Users\UpdateWorker;
use App\Enums\MembershipStatus;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/** @return array{owner: User, membership: Membership, administrator: Role, worker: Role} */
function floristWorkerContext(string $companyName): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => $companyName,
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->firstOrFail();
    $administrator = Role::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('name', 'Administrador')->firstOrFail();
    $worker = Role::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('name', 'Trabajador')->firstOrFail();

    return compact('owner', 'membership', 'administrator', 'worker');
}

test('an owner creates a worker with a hashed temporary password and a company role', function () {
    $context = floristWorkerContext('Florería Central');

    $membership = app(CreateWorker::class)->handle($context['membership'], $context['worker'], [
        'name' => 'Ana Florista',
        'email' => 'ana@example.com',
        'password' => 'Temporary-Password-123!',
    ]);

    expect($membership->company_id)->toBe($context['membership']->company_id)
        ->and($membership->is_owner)->toBeFalse()
        ->and($membership->roles->sole()->name)->toBe('Trabajador')
        ->and($membership->user->must_change_password)->toBeTrue()
        ->and($membership->user->password)->not->toBe('Temporary-Password-123!')
        ->and(Hash::check('Temporary-Password-123!', $membership->user->password))->toBeTrue();
});

test('an owner can change a worker role and suspend or reactivate the membership', function () {
    $context = floristWorkerContext('Florería Norte');
    $worker = app(CreateWorker::class)->handle($context['membership'], $context['worker'], [
        'name' => 'Luis', 'email' => 'luis@example.com', 'password' => 'Temporary-Password-123!',
    ]);

    app(UpdateWorker::class)->handle($context['membership'], $worker, $context['administrator'], [
        'name' => 'Luis Flores', 'email' => 'luis.flores@example.com',
    ]);
    app(ChangeMembershipStatus::class)->handle($context['membership'], $worker, MembershipStatus::Suspended);

    expect($worker->refresh()->status)->toBe(MembershipStatus::Suspended)
        ->and($worker->roles()->sole()->name)->toBe('Administrador')
        ->and($worker->user->refresh()->status->value)->toBe('active');

    app(ChangeMembershipStatus::class)->handle($context['membership'], $worker, MembershipStatus::Active);
    expect($worker->refresh()->status)->toBe(MembershipStatus::Active);
});

test('a worker cannot be administered from another company', function () {
    $first = floristWorkerContext('Florería Uno');
    $second = floristWorkerContext('Florería Dos');
    $worker = app(CreateWorker::class)->handle($first['membership'], $first['worker'], [
        'name' => 'María', 'email' => 'maria@example.com', 'password' => 'Temporary-Password-123!',
    ]);

    expect(fn () => app(UpdateWorker::class)->handle(
        $second['membership'], $worker, $second['worker'], ['name' => 'Intruso', 'email' => 'intruso@example.com'],
    ))->toThrow(DomainException::class, 'empresa actual');
});
