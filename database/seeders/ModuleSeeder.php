<?php

namespace Database\Seeders;

use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Module::query()->upsert([
            ['code' => ModuleCode::Core->value, 'name' => 'Core', 'sort_order' => 10, 'is_active' => true],
            ['code' => ModuleCode::Catalog->value, 'name' => 'Catalog', 'sort_order' => 20, 'is_active' => true],
            ['code' => ModuleCode::Inventory->value, 'name' => 'Inventory', 'sort_order' => 30, 'is_active' => true],
            ['code' => ModuleCode::Sales->value, 'name' => 'Sales', 'sort_order' => 40, 'is_active' => true],
            ['code' => ModuleCode::Finance->value, 'name' => 'Finance', 'sort_order' => 50, 'is_active' => true],
            ['code' => ModuleCode::Cash->value, 'name' => 'Cash', 'sort_order' => 60, 'is_active' => true],
        ], ['code'], ['name', 'sort_order', 'is_active']);

        $finance = Module::query()->where('code', ModuleCode::Finance)->firstOrFail();

        Company::query()->eachById(function (Company $company) use ($finance): void {
            CompanyModule::query()->withoutGlobalScope('company')->firstOrCreate(
                ['company_id' => $company->getKey(), 'module_id' => $finance->getKey()],
                ['status' => CompanyModuleStatus::Enabled, 'enabled_at' => now()],
            );
        });
    }
}
