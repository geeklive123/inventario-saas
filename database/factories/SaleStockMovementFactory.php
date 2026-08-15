<?php

namespace Database\Factories;

use App\Enums\SaleStockMovementKind;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\SaleStockMovement;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleStockMovement>
 */
class SaleStockMovementFactory extends Factory
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
            'stock_movement_id' => fn (array $attributes) => StockMovement::factory()->create([
                'company_id' => $attributes['company_id'],
                'warehouse_id' => Warehouse::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
                'created_by_membership_id' => Membership::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            ])->getKey(),
            'kind' => SaleStockMovementKind::Consumption,
        ];
    }
}
