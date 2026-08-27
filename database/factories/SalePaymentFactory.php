<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
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
            'payment_method_id' => fn (array $attributes) => PaymentMethod::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'payment_method_name' => fake()->randomElement(['Efectivo', 'QR', 'Transferencia']),
            'received_by_membership_id' => fn (array $attributes) => Membership::factory()->create(['company_id' => $attributes['company_id']])->getKey(),
            'amount_base' => 100,
            'occurred_at' => now(),
        ];
    }
}
