<?php

namespace App\Actions\Modules;

use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\CompanyModule;
use App\Models\Membership;
use App\Models\Module;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class SetModuleStatus
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function handle(
        Membership $actor,
        Module $module,
        CompanyModuleStatus $status,
        ?array $settings = null,
    ): CompanyModule {
        if (! $this->access->allows($actor->user, $actor->company, 'core.modules.manage')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        if ($module->code === ModuleCode::Core && $status === CompanyModuleStatus::Disabled) {
            throw new DomainException('The core module cannot be disabled.');
        }

        return DB::transaction(function () use ($actor, $module, $status, $settings): CompanyModule {
            $companyModule = CompanyModule::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $actor->company_id)
                ->where('module_id', $module->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $companyModule->update([
                'status' => $status,
                'settings' => $settings ?? $companyModule->settings,
                'enabled_at' => $status === CompanyModuleStatus::Enabled ? now() : $companyModule->enabled_at,
                'disabled_at' => $status === CompanyModuleStatus::Disabled ? now() : null,
                'enabled_by_membership_id' => $status === CompanyModuleStatus::Enabled
                    ? $actor->getKey()
                    : $companyModule->enabled_by_membership_id,
            ]);

            return $companyModule->refresh();
        }, attempts: 3);
    }
}
