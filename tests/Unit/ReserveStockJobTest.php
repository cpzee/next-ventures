<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\ReserveStockJob;
use App\Models\Product;
use App\Models\Order;

class ReserveStockJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_stock_is_idempotent()
    {
        // create product
        $product = Product::create(['name' => 'Widget', 'stock' => 10, 'price_cents' => 1000]);
        // create a customer so FK constraints are satisfied
        \App\Models\Customer::create(['name' => 'Test Customer', 'email' => 'c@example.test']);
        // create order with metadata items
        $order = Order::create([
            'external_id' => 'ord_1',
            'customer_id' => 1,
            'total_cents' => 1000,
            'metadata' => ['items' => [['product_id' => $product->id, 'qty' => 2]]],
        ]);

        // run ReserveStockJob twice
        $job = new ReserveStockJob($order->id);
        $job->handle();

        $product->refresh();
        $this->assertEquals(8, $product->stock);

        // run again (should be skipped)
        $job2 = new ReserveStockJob($order->id);
        $job2->handle();

        $product->refresh();
        $this->assertEquals(8, $product->stock, 'Stock should not be decremented twice');
    }
}
