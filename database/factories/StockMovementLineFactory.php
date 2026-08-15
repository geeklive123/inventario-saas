<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovementLine>
 */
class StockMovementLineFactory extends Factory
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
            'stock_movement_id' => fn (array $attributes) => StockMovement::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'quantity' => 1,
            'quantity_before' => 0,
            'quantity_after' => 1,
            'unit_cost_base' => 1,
            'total_cost_base' => 1,
            'inventory_value_before_base' => 0,
            'inventory_value_after_base' => 1,
            'average_unit_cost_before_base' => 0,
            'average_unit_cost_after_base' => 1,
            'cost_variance_base' => 0,
        ];
    }
}
