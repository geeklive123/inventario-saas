<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
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
            'branch_id' => fn (array $attributes) => Branch::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'code' => fake()->unique()->bothify('WH-###'),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->getKey(),
        ]);
    }
}
