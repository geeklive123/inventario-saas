<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->optional()->company(),
            'tax_identifier' => fake()->optional()->numerify('##########'),
            'base_currency_id' => Currency::factory(),
            'timezone' => 'America/La_Paz',
            'locale' => 'es',
            'allow_negative_stock' => false,
            'status' => CompanyStatus::Active,
        ];
    }
}
