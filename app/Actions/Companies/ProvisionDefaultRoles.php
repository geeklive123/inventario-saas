<?php

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProvisionDefaultRoles
{
    /**
     * @return Collection<string, Role>
     */
    public function handle(Company $company): Collection
    {
        return DB::transaction(function () use ($company): Collection {
            $administrator = Role::query()->withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->getKey(), 'name' => 'Administrador'],
                ['description' => 'Administración completa de la florería.', 'is_system' => true, 'is_active' => true],
            );
            $worker = Role::query()->withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->getKey(), 'name' => 'Trabajador'],
                ['description' => 'Operación de insumos, ramos, inventario y mermas.', 'is_system' => true, 'is_active' => true],
            );

            $allPermissionIds = Permission::query()->where('is_active', true)->pluck('id');
            $workerPermissionIds = Permission::query()->whereIn('code', [
                'catalog.view',
                'catalog.create',
                'catalog.update',
                'inventory.view',
                'inventory.opening',
                'inventory.adjust',
                'inventory.waste',
                'sales.view',
                'sales.create',
            ])->where('is_active', true)->pluck('id');

            $administrator->permissions()->syncWithPivotValues($allPermissionIds, ['company_id' => $company->getKey()]);
            $worker->permissions()->syncWithPivotValues($workerPermissionIds, ['company_id' => $company->getKey()]);

            return collect([
                'administrator' => $administrator,
                'worker' => $worker,
            ]);
        }, attempts: 3);
    }
}
