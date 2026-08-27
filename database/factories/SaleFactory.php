<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
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
            'sequence_number' => fake()->unique()->numberBetween(1, 999999),
            'number' => fn (array $attributes) => 'V-'.str_pad((string) $attributes['sequence_number'], 6, '0', STR_PAD_LEFT),
            'branch_id' => fn (array $attributes) => Branch::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'warehouse_id' => fn (array $attributes) => Warehouse::factory()->create([
                'company_id' => $attributes['company_id'],
                'branch_id' => $attributes['branch_id'],
            ])->getKey(),
            'customer_id' => null,
            'customer_name' => fake()->optional()->name(),
            'branch_name' => fake()->words(2, true),
            'warehouse_name' => fake()->words(2, true),
            'status' => SaleStatus::Confirmed,
            'order_status' => SaleOrderStatus::Reserved,
            'payment_status' => PaymentStatus::Pending,
            'subtotal_base' => 100,
            'extras_total_base' => 0,
            'total_base' => 100,
            'paid_total_base' => 0,
            'balance_due_base' => 100,
            'total_cost_base' => 60,
            'gross_margin_base' => 40,
            'confirmed_by_membership_id' => fn (array $attributes) => Membership::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'voided_by_membership_id' => null,
            'occurred_at' => now(),
            'delivery_at' => now()->addDay(),
            'delivery_updated_by_membership_id' => null,
            'voided_at' => null,
            'void_reason' => null,
        ];
    }
}
