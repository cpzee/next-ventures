<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;

class CustomerSeeder extends Seeder
{
    public function run()
    {
        // Customer::truncate();

        Customer::insert([
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}