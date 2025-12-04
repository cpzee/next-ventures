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
            ['id' => 1, 'name' => 'A', 'email' => 'a@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'B', 'email' => 'b@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'C', 'email' => 'c@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}