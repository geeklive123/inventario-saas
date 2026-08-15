<?php

namespace Database\Factories;

use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'unit_id' => fn (array $attributes) => Unit::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'category_id' => null,
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'barcode' => fake()->optional()->numerify('#############'),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'item_type' => ProductItemType::Physical,
            'inventory_behavior' => InventoryBehavior::Self,
            'is_sellable' => true,
            'sale_price_base' => fake()->randomFloat(4, 0, 1000),
            'fallback_unit_cost_base' => fake()->optional()->randomFloat(4, 0, 500),
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'item_type' => ProductItemType::Service,
            'inventory_behavior' => InventoryBehavior::None,
        ]);
    }

    public function composed(): static
    {
        return $this->state(fn () => [
            'item_type' => ProductItemType::Physical,
            'inventory_behavior' => InventoryBehavior::Components,
        ]);
    }
}
