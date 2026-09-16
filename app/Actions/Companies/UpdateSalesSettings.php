<?php

namespace App\Actions\Companies;

use App\Enums\ModuleCode;
use App\Models\CompanyModule;
use App\Models\Membership;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateSalesSettings
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, bool $allowBackdatedSales): CompanyModule
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $allowBackdatedSales): CompanyModule {
            $lockedActor = Membership::query()
                ->withoutGlobalScope('company')
                ->with(['user', 'company'])
                ->whereKey($actor->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize($lockedActor);

            $companyModule = CompanyModule::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $lockedActor->company_id)
                ->whereHas('module', fn ($query) => $query->where('code', ModuleCode::Sales))
                ->lockForUpdate()
                ->firstOrFail();
            $settings = is_array($companyModule->settings) ? $companyModule->settings : [];
            $settings['allow_backdated_sales'] = $allowBackdatedSales;
            $companyModule->update(['settings' => $settings]);

            return $companyModule->refresh();
        }, attempts: 3);
    }

    private function authorize(Membership $actor): void
    {
        if (! $this->access->isOwnerOrAdministrator($actor->user, $actor->company)
            || ! $this->access->allows($actor->user, $actor->company, 'core.modules.manage')) {
            throw new DomainException('La membership responsable no puede configurar las ventas atrasadas.');
        }
    }
}
