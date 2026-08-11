<?php

namespace Database\Seeders;

use App\Enums\ModuleCode;
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
            ['code' => ModuleCode::Cash->value, 'name' => 'Cash', 'sort_order' => 50, 'is_active' => true],
        ], ['code'], ['name', 'sort_order', 'is_active']);
    }
}
