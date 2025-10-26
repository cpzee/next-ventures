<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run()
    {
        // Product::truncate();

        Product::insert([
            ['id' => 1, 'name' => 'Laptop', 'price_cents' => 150000, 'stock' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Phone', 'price_cents' => 70000, 'stock' => 15, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Headphones', 'price_cents' => 20000, 'stock' => 20, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}