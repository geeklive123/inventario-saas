<?php

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Membership;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
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
            'number' => fn (array $attributes) => 'G-'.str_pad((string) $attributes['sequence_number'], 6, '0', STR_PAD_LEFT),
            'branch_id' => fn (array $attributes) => Branch::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'membership_id' => fn (array $attributes) => Membership::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'expense_category_id' => fn (array $attributes) => ExpenseCategory::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'payment_method_id' => fn (array $attributes) => PaymentMethod::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'category_name' => fake()->words(2, true),
            'payment_method_name' => fake()->word(),
            'branch_name' => fake()->words(2, true),
            'reference' => fake()->optional()->numerify('REF-####'),
            'concept' => fake()->sentence(3),
            'amount_base' => fake()->randomFloat(4, 1, 1000),
            'occurred_at' => now(),
            'notes' => fake()->optional()->sentence(),
            'status' => ExpenseStatus::Confirmed,
            'cancelled_by_membership_id' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }
}
