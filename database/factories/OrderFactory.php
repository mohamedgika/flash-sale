<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Order;


/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;
    public function definition()
    {
        return [
            'hold_id'    => \App\Models\Hold::factory(),
            'product_id' => \App\Models\Product::factory(),
            'quantity'   => $this->faker->numberBetween(1, 10),
            'total'      => $this->faker->randomFloat(2, 10, 1000), // adjust to price domain
            'status'     => $this->faker->randomElement(['pending', 'paid', 'failed']),    
        ];
    }
}
