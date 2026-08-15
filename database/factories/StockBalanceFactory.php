<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
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
            'warehouse_id' => fn (array $attributes) => Warehouse::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'quantity' => 0,
            'inventory_value_base' => 0,
            'average_unit_cost_base' => 0,
            'last_inbound_unit_cost_base' => null,
        ];
    }
}
