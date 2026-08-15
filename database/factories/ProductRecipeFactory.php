<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRecipe>
 */
class ProductRecipeFactory extends Factory
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
            'product_id' => fn (array $attributes) => Product::factory()->composed()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'version' => 1,
            'yield_quantity' => 1,
            'active_slot' => null,
            'created_by_membership_id' => null,
        ];
    }
}
