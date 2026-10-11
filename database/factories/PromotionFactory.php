<?php

namespace Database\Factories;

use App\Enums\PromotionStatus;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'summary' => fake()->sentence(12),
            'description' => fake()->paragraphs(3, true),
            'prize' => fake()->words(3, true),
            'category' => fake()->randomElement(['Eletrônicos', 'Veículos', 'Experiências', 'Diversos']),
            'unit_price_cents' => 500,
            'number_start' => 1,
            'total_numbers' => 100,
            'min_per_order' => 1,
            'max_per_order' => 50,
            'allow_random' => true,
            'reservation_minutes' => 15,
            'payment_methods' => ['pix'],
            'status' => PromotionStatus::Draft,
            'is_demo' => false,
        ];
    }

    /** Publicada e aberta agora. */
    public function open(): static
    {
        return $this->state(fn () => [
            'status' => PromotionStatus::Published,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }
}
