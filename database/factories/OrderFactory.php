<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_code' => Str::upper(Str::random(10)),
            'access_token' => Str::random(40),
            'checkout_key' => (string) Str::uuid(),
            'promotion_id' => Promotion::factory()->open(),
            'buyer_name' => fake()->name(),
            'buyer_email' => fake()->safeEmail(),
            'buyer_cpf' => fake()->numerify('###########'),
            'quantity' => 1,
            'unit_price_cents' => 500,
            'total_cents' => 500,
            'status' => OrderStatus::AwaitingPayment,
            'reserved_until' => now()->addMinutes(15),
        ];
    }
}
