<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Company;
use App\Models\Membership;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
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
            'type' => StockMovementType::AdjustmentIn,
            'reversal_of_movement_id' => null,
            'created_by_membership_id' => fn (array $attributes) => Membership::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'reason' => fake()->optional()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
