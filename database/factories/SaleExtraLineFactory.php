<?php

namespace Database\Factories;

use App\Enums\SaleExtraType;
use App\Models\Sale;
use App\Models\SaleExtra;
use App\Models\SaleExtraLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleExtraLine>
 */
class SaleExtraLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn () => Sale::factory()->create()->company_id,
            'sale_id' => fn (array $attributes) => Sale::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'sale_extra_id' => fn (array $attributes) => SaleExtra::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'extra_name' => fake()->words(2, true),
            'extra_type' => SaleExtraType::Service,
            'quantity' => 1,
            'unit_price_base' => 10,
            'subtotal_base' => 10,
        ];
    }
}
