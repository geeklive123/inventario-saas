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
                'catalog.bouquets.price.update' => 'Update bouquet prices',
                'catalog.recipes.manage' => 'Manage bouquet recipes',
            ],
            ModuleCode::Inventory->value => [
                'inventory.view' => 'View inventory',
                'inventory.opening' => 'Register opening stock',
                'inventory.adjust' => 'Adjust inventory',
                'inventory.reverse' => 'Reverse inventory movements',
                'inventory.waste' => 'Register inventory waste',
                'reports.inventory.view' => 'View inventory reports',
            ],
            ModuleCode::Sales->value => [
                'sales.view' => 'View sales',
                'sales.create' => 'Create sales',
                'sales.void' => 'Void sales',
                'sales.manage' => 'Manage sales (legacy)',
                'sales.costs.view' => 'View sales costs',
                'sales.profits.view' => 'View sales profits',
                'sales.balances.view' => 'View outstanding sale balances',
                'sales.payments.create' => 'Register sale payments',
                'sales.extras.manage' => 'Manage sale extras',
                'sales.extras.price.update' => 'Override sale extra prices',
                'reports.sales.view' => 'View sales reports',
            ],
            ModuleCode::Finance->value => [
                'finance.sales_income.view' => 'View sales income',
                'finance.expenses.view' => 'View expenses',
                'finance.expenses.create' => 'Create expenses',
                'finance.expenses.update' => 'Update expenses',
                'finance.expenses.cancel' => 'Cancel expenses',
                'finance.expense_categories.manage' => 'Manage expense categories',
                'reports.expenses.view' => 'View expense reports',
                'reports.financial.view' => 'View financial reports',
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
