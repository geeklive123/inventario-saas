<?php

namespace Database\Factories;

use App\Enums\SaleInventoryPendingStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleInventoryPending;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleInventoryPending>
 */
class SaleInventoryPendingFactory extends Factory
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
            'sale_id' => fn (array $attributes) => Sale::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'sale_item_id' => fn (array $attributes) => SaleItem::factory()->create([
                'company_id' => $attributes['company_id'],
                'sale_id' => $attributes['sale_id'],
            ])->getKey(),
            'warehouse_id' => fn (array $attributes) => Sale::query()
                ->withoutGlobalScope('company')->whereKey($attributes['sale_id'])->value('warehouse_id'),
            'original_component_product_id' => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'stock_movement_id' => null,
            'original_component_name' => fake()->words(2, true),
            'original_component_sku' => fake()->unique()->bothify('INS-####'),
            'unit_symbol' => 'UND',
            'required_quantity' => 1,
            'regularized_quantity' => 0,
            'status' => SaleInventoryPendingStatus::Pending,
            'regularized_at' => null,
            'regularized_by_membership_id' => null,
        ];
    }
}
