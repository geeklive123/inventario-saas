<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
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
            'code' => fake()->unique()->bothify('U-###'),
            'name' => fake()->word(),
            'symbol' => fake()->randomElement(['u', 'kg', 'm', 'l']),
            'decimal_places' => 0,
            'is_active' => true,
        ];
    }
}
