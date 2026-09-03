<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SaleItemComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItemComponent>
 */
class SaleItemComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn () => SaleItem::factory()->create()->company_id,
            'sale_item_id' => fn (array $attributes) => SaleItem::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'product_id' => fn (array $attributes) => Product::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'product_name' => fake()->words(2, true),
            'product_sku' => fake()->unique()->bothify('INS-####'),
            'unit_symbol' => 'UND',
            'recipe_quantity' => 1,
            'customization_quantity' => 0,
            'waste_percentage' => 0,
            'quantity_consumed' => 1,
            'customization_quantity_consumed' => 0,
            'customization_unit_price_base' => 0,
            'customization_total_price_base' => 0,
            'customization_note' => null,
            'unit_cost_base' => 5,
            'total_cost_base' => 5,
            'customization_total_cost_base' => 0,
        ];
    }
}
