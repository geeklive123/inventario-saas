<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
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
            'product_id' => fn (array $attributes) => Product::factory()->composed()->create(['company_id' => $attributes['company_id']])->getKey(),
            'product_recipe_id' => fn (array $attributes) => ProductRecipe::factory()->create([
                'company_id' => $attributes['company_id'],
                'product_id' => $attributes['product_id'],
                'active_slot' => 1,
            ])->getKey(),
            'recipe_version' => 1,
            'product_name' => fake()->words(3, true),
            'product_sku' => fake()->unique()->bothify('RAMO-####'),
            'unit_symbol' => 'UND',
            'quantity' => 1,
            'unit_price_base' => 100,
            'subtotal_base' => 100,
            'unit_cost_base' => 60,
            'total_cost_base' => 60,
            'gross_margin_base' => 40,
        ];
    }
}
