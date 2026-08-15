<?php

namespace App\Support\Authorization;

use App\Enums\CompanyModuleStatus;
use App\Enums\CompanyStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

class CompanyAccess
{
    public function __construct(private CurrentCompany $currentCompany) {}

    public function allowsCurrent(User $user, string $permissionCode, bool $mutation = true): bool
    {
        if (! $this->currentCompany->isResolved()) {
            return false;
        }

        return $this->allows($user, $this->currentCompany->company(), $permissionCode, $mutation);
    }

    public function allows(User $user, Company $company, string $permissionCode, bool $mutation = true): bool
    {
        if ($company->status !== CompanyStatus::Active) {
            return false;
        }

        $membership = $this->activeMembership($user, $company);

        if ($membership === null) {
            return false;
        }

        $permission = Permission::query()
            ->with('module:id,is_active')
            ->where('code', $permissionCode)
            ->where('is_active', true)
            ->first();

        if ($permission === null || ! $permission->module->is_active) {
            return false;
        }

        $companyModule = CompanyModule::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('module_id', $permission->module_id)
            ->first();

        if ($companyModule === null) {
            return false;
        }

        if ($mutation && $companyModule->status !== CompanyModuleStatus::Enabled) {
            return false;
        }

        if ($membership->is_owner) {
            return true;
        }

        return $membership->roles()
            ->where('roles.is_active', true)
            ->whereHas('permissions', fn ($query) => $query
                ->whereKey($permission->getKey())
                ->where('permissions.is_active', true))
            ->exists();
    }

    public function activeMembership(User $user, Company $company): ?Membership
    {
        return Membership::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->first();
    }

    public function moduleEnabled(Company $company, ModuleCode $moduleCode): bool
    {
        return CompanyModule::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('status', CompanyModuleStatus::Enabled)
            ->whereHas('module', fn ($query) => $query
                ->where('code', $moduleCode)
                ->where('is_active', true))
            ->exists();
    }

    public function moduleEnabledCurrent(ModuleCode $moduleCode): bool
    {
        return $this->currentCompany->isResolved()
            && $this->moduleEnabled($this->currentCompany->company(), $moduleCode);
    }
}
