<?php

namespace Database\Factories;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class HoldFactory extends Factory
{
    protected $model = Hold::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'expires_at' => now()->addMinutes(2),
            'consumed' => false,
        ];
    }

    /**
     * Hold that has expired
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Hold that has been consumed
     */
    public function consumed(): static
    {
        return $this->state(fn (array $attributes) => [
            'consumed' => true,
        ]);
    }

    /**
     * Hold with specific quantity
     */
    public function withQuantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }
}