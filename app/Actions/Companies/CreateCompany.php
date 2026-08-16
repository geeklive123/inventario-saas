<?php

namespace App\Actions\Companies;

use App\Enums\CompanyModuleStatus;
use App\Enums\CompanyStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Membership;
use App\Models\Module;
use App\Models\User;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateCompany
{
    public function __construct(
        private ProvisionDefaultRoles $provisionDefaultRoles,
        private ProvisionDefaultPaymentMethods $provisionDefaultPaymentMethods,
        private ProvisionDefaultExpenseCategories $provisionDefaultExpenseCategories,
    ) {}

    /**
     * @param  array{name: string, base_currency_id: int, legal_name?: string|null, tax_identifier?: string|null, timezone: string, locale: string, allow_negative_stock?: bool}  $attributes
     */
    public function handle(User $owner, array $attributes): Company
    {
        if ($owner->status !== UserStatus::Active) {
            throw new DomainException('A deactivated user cannot create a company.');
        }

        return DB::transaction(function () use ($owner, $attributes): Company {
            $company = Company::query()->create([
                ...Arr::only($attributes, [
                    'name',
                    'legal_name',
                    'tax_identifier',
                    'base_currency_id',
                    'timezone',
                    'locale',
                    'allow_negative_stock',
                ]),
                'status' => CompanyStatus::Active,
            ]);

            $membership = Membership::query()->create([
                'company_id' => $company->getKey(),
                'user_id' => $owner->getKey(),
                'status' => MembershipStatus::Active,
                'is_owner' => true,
                'joined_at' => now(),
            ]);

            $modules = Module::query()->where('is_active', true)->get();

            if ($modules->isEmpty() || ! $modules->contains('code', ModuleCode::Core)) {
                throw new DomainException('The core module catalog has not been seeded.');
            }

            foreach ($modules as $module) {
                $enabledByDefault = in_array($module->code, [ModuleCode::Core, ModuleCode::Finance], true);

                CompanyModule::query()->create([
                    'company_id' => $company->getKey(),
                    'module_id' => $module->getKey(),
                    'status' => $enabledByDefault ? CompanyModuleStatus::Enabled : CompanyModuleStatus::Disabled,
                    'enabled_at' => $enabledByDefault ? now() : null,
                    'disabled_at' => $enabledByDefault ? null : now(),
                    'enabled_by_membership_id' => $enabledByDefault ? $membership->getKey() : null,
                ]);
            }

            $this->provisionDefaultRoles->handle($company);
            $this->provisionDefaultPaymentMethods->handle($company);
            $this->provisionDefaultExpenseCategories->handle($company);

            return $company->load(['baseCurrency', 'memberships', 'roles', 'companyModules.module', 'paymentMethods']);
        }, attempts: 3);
    }
}
