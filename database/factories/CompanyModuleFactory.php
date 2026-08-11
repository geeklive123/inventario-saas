<?php

namespace Database\Factories;

use App\Enums\CompanyModuleStatus;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyModule>
 */
class CompanyModuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'module_id' => Module::factory(),
            'status' => CompanyModuleStatus::Disabled,
            'settings' => null,
            'enabled_at' => null,
            'disabled_at' => now(),
            'enabled_by_membership_id' => null,
        ];
    }
}
