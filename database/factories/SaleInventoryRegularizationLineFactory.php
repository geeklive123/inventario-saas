<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SaleInventoryPending;
use App\Models\SaleInventoryRegularizationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleInventoryRegularizationLine>
 */
class SaleInventoryRegularizationLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn () => SaleInventoryPending::factory()->create()->company_id,
            'sale_inventory_pending_id' => fn (array $attributes) => SaleInventoryPending::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'actual_product_id' => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'actual_product_name' => fake()->words(2, true),
            'actual_product_sku' => fake()->unique()->bothify('INS-####'),
            'unit_symbol' => 'UND',
            'quantity' => 1,
            'unit_cost_base' => 5,
            'total_cost_base' => 5,
        ];
    }
}
