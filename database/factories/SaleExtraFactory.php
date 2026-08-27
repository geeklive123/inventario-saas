<?php

namespace Database\Factories;

use App\Enums\SaleExtraType;
use App\Models\Company;
use App\Models\SaleExtra;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleExtra>
 */
class SaleExtraFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'default_price_base' => fake()->randomFloat(4, 1, 100),
            'type' => SaleExtraType::Service,
            'inventory_product_id' => null,
            'is_active' => true,
        ];
    }
}
