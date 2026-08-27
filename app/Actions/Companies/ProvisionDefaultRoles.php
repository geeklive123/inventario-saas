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
            $seller = Role::query()->withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->getKey(), 'name' => 'Vendedor'],
                ['description' => 'Registro de ventas y consulta operativa sin información financiera.', 'is_system' => true, 'is_active' => true],
            );
            $inventory = Role::query()->withoutGlobalScope('company')->updateOrCreate(
                ['company_id' => $company->getKey(), 'name' => 'Inventario'],
                ['description' => 'Gestión de catálogo, existencias y mermas.', 'is_system' => true, 'is_active' => true],
            );

            $allPermissionIds = Permission::query()->where('is_active', true)->pluck('id');
            $workerPermissionIds = Permission::query()->whereIn('code', [
                'catalog.view',
                'catalog.create',
                'catalog.update',
                'catalog.bouquets.price.update',
                'catalog.recipes.manage',
                'inventory.view',
                'inventory.opening',
                'inventory.adjust',
                'inventory.waste',
                'sales.view',
                'sales.create',
                'sales.balances.view',
                'sales.payments.create',
            ])->where('is_active', true)->pluck('id');
            $sellerPermissionIds = Permission::query()->whereIn('code', [
                'catalog.view', 'inventory.view', 'sales.view', 'sales.create',
                'sales.balances.view', 'sales.payments.create',
            ])->where('is_active', true)->pluck('id');
            $inventoryPermissionIds = Permission::query()->whereIn('code', [
                'catalog.view', 'inventory.view', 'inventory.adjust', 'inventory.waste',
            ])->where('is_active', true)->pluck('id');

            $administrator->permissions()->syncWithPivotValues($allPermissionIds, ['company_id' => $company->getKey()]);
            $worker->permissions()->syncWithPivotValues($workerPermissionIds, ['company_id' => $company->getKey()]);
            $seller->permissions()->syncWithPivotValues($sellerPermissionIds, ['company_id' => $company->getKey()]);
            $inventory->permissions()->syncWithPivotValues($inventoryPermissionIds, ['company_id' => $company->getKey()]);

            return collect([
                'administrator' => $administrator,
                'worker' => $worker,
                'seller' => $seller,
                'inventory' => $inventory,
            ]);
        }, attempts: 3);
    }
}
