<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRecipeItem>
 */
class ProductRecipeItemFactory extends Factory
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
            'product_recipe_id' => fn (array $attributes) => ProductRecipe::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'component_product_id' => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'quantity' => 1,
            'waste_percentage' => 0,
        ];
    }
}
