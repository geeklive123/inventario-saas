<?php

namespace App\Actions\Companies;

use App\Actions\Roles\AssignRole;
use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateInitialAdmin
{
    public function __construct(
        private CreateCompany $createCompany,
        private AssignRole $assignRole,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, company_name: string, currency_code: string, timezone: string, locale: string}  $attributes
     * @return array{user: User, company: Company, membership: Membership, role: Role}
     */
    public function handle(array $attributes): array
    {
        return Cache::lock('bootstrap:initial-admin', 30)->block(10, function () use ($attributes): array {
            return DB::transaction(function () use ($attributes): array {
                $this->ensureApplicationHasNotBeenBootstrapped();

                $currency = Currency::query()
                    ->where('code', Str::upper($attributes['currency_code']))
                    ->where('is_active', true)
                    ->first();

                if ($currency === null) {
                    throw new DomainException('La moneda base no existe o está inactiva.');
                }

                $user = User::query()->create([
                    'name' => $attributes['name'],
                    'email' => Str::lower($attributes['email']),
                    'password' => $attributes['password'],
                    'must_change_password' => true,
                    'status' => UserStatus::Active,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $company = $this->createCompany->handle($user, [
                    'name' => $attributes['company_name'],
                    'base_currency_id' => $currency->getKey(),
                    'timezone' => $attributes['timezone'],
                    'locale' => $attributes['locale'],
                ]);

                $membership = Membership::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $company->getKey())
                    ->where('user_id', $user->getKey())
                    ->where('status', MembershipStatus::Active)
                    ->where('is_owner', true)
                    ->firstOrFail();
                $administrator = Role::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $company->getKey())
                    ->where('name', 'Administrador')
                    ->where('is_active', true)
                    ->firstOrFail();

                $this->assignRole->handle($membership, $membership, $administrator);

                return [
                    'user' => $user->refresh(),
                    'company' => $company->refresh(),
                    'membership' => $membership->load('roles.permissions'),
                    'role' => $administrator->load('permissions'),
                ];
            }, attempts: 3);
        });
    }

    private function ensureApplicationHasNotBeenBootstrapped(): void
    {
        if (User::query()->exists() || Company::query()->exists() || Membership::query()->withoutGlobalScope('company')->exists()) {
            throw new DomainException('El bootstrap inicial ya no está disponible porque existen usuarios, empresas o memberships.');
        }
    }
}
