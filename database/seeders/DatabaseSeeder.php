<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{

    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        

        Product::truncate();
        DB::table('holds')->truncate();
        DB::table('orders')->truncate();
        DB::table('idempotency_keys')->truncate();
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $products = [
            [
                'name' => 'iPhone 15 Pro Max - Limited Edition',
                'price' => 1199.99,
                'stock' => 50,
            ],
            [
                'name' => 'Samsung Galaxy S24 Ultra',
                'price' => 1099.99,
                'stock' => 30,
            ],
            [
                'name' => 'MacBook Air M3 - 16GB RAM',
                'price' => 1499.99,
                'stock' => 20,
            ],
            [
                'name' => 'Sony PlayStation 5 Pro Bundle',
                'price' => 599.99,
                'stock' => 100,
            ],
            [
                'name' => 'AirPods Pro 2nd Gen',
                'price' => 249.99,
                'stock' => 200,
            ],
            [
                'name' => 'Dell XPS 15 Laptop',
                'price' => 1899.99,
                'stock' => 15,
            ],
            [
                'name' => 'Apple Watch Series 9',
                'price' => 399.99,
                'stock' => 75,
            ],
            [
                'name' => 'iPad Pro 12.9" M2',
                'price' => 1099.99,
                'stock' => 40,
            ],
            [
                'name' => 'Nintendo Switch OLED',
                'price' => 349.99,
                'stock' => 150,
            ],
            [
                'name' => 'Bose QuietComfort Ultra Headphones',
                'price' => 429.99,
                'stock' => 60,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }

        $this->command->info('Created ' . count($products) . ' flash sale products');
        $this->command->info('Total stock available: ' . array_sum(array_column($products, 'stock')));
    }
}