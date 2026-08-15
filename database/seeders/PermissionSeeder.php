<?php

namespace Database\Seeders;

use App\Enums\ModuleCode;
use App\Models\Module;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            ModuleCode::Core->value => [
                'core.companies.view' => 'View companies',
                'core.companies.update' => 'Update companies',
                'core.memberships.view' => 'View memberships',
                'core.memberships.create' => 'Create memberships',
                'core.memberships.suspend' => 'Suspend memberships',
                'core.roles.view' => 'View roles',
                'core.roles.create' => 'Create roles',
                'core.roles.update' => 'Update roles',
                'core.roles.assign' => 'Assign roles',
                'core.modules.view' => 'View modules',
                'core.modules.manage' => 'Manage modules',
                'core.branches.view' => 'View branches',
                'core.branches.manage' => 'Manage branches',
                'core.warehouses.view' => 'View warehouses',
                'core.warehouses.manage' => 'Manage warehouses',
                'core.users.view' => 'View workers',
                'core.users.create' => 'Create workers',
                'core.users.update' => 'Update workers',
                'core.users.assign_roles' => 'Assign worker roles',
                'core.users.suspend' => 'Suspend workers',
            ],
            ModuleCode::Catalog->value => [
                'catalog.view' => 'View catalog',
                'catalog.create' => 'Create catalog products',
                'catalog.update' => 'Update catalog products',
                'catalog.deactivate' => 'Deactivate catalog products',
            ],
            ModuleCode::Inventory->value => [
                'inventory.view' => 'View inventory',
                'inventory.opening' => 'Register opening stock',
                'inventory.adjust' => 'Adjust inventory',
                'inventory.reverse' => 'Reverse inventory movements',
                'inventory.waste' => 'Register inventory waste',
            ],
            ModuleCode::Sales->value => [
                'sales.view' => 'View sales',
                'sales.create' => 'Create sales',
                'sales.void' => 'Void sales',
                'sales.manage' => 'Manage sales (legacy)',
            ],
            ModuleCode::Cash->value => [
                'cash.view' => 'View cash',
                'cash.manage' => 'Manage cash',
            ],
        ];

        $modules = Module::query()->get()->keyBy(fn (Module $module): string => $module->code->value);

        foreach ($permissions as $moduleCode => $modulePermissions) {
            $module = $modules->get($moduleCode);

            foreach ($modulePermissions as $code => $name) {
                Permission::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'module_id' => $module->getKey(),
                        'name' => $name,
                        'description' => null,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
