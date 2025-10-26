<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use App\Jobs\ProcessOrderJob;
use App\Jobs\ProcessRefundJob;
use App\Models\Product;
use App\Models\Order;
use App\Models\Refund;

/**
 * Integration test for MySQL + Redis. Run this locally on macOS where MySQL and Redis
 * are installed and accessible. This test assumes phpunit.xml is configured for MySQL
 * or you pass DB env vars when running.
 */
class OrderWorkflowMySqlRedisTest extends TestCase
{
    use RefreshDatabase;

    /** @group integration */
    public function test_order_flow_and_refund_with_mysql_and_redis()
    {
        // ensure Redis is available and clean
        Redis::flushdb();

        // create customer and product
        \App\Models\Customer::create(['name' => 'Integration Customer', 'email' => 'int@example.test']);
        $product = Product::create(['name' => 'Int Widget', 'stock' => 10, 'price_cents' => 1500]);

        $orderData = [
            'order_id' => 'int-ord-1',
            'customer_id' => 1,
            'total_cents' => 1500,
            'currency' => 'USD',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ];

        // dispatch the order processing job (this will run transactions and update Redis via KpiService)
        $job = new ProcessOrderJob($orderData);
        $job->handle();

        $order = Order::where('external_id', $orderData['order_id'])->first();
        $this->assertNotNull($order);

        // Run chained jobs explicitly if queue sync mode
        if ($order->status !== 'completed') {
            (new \App\Jobs\ReserveStockJob($order->id))->handle();
            (new \App\Jobs\SimulatePaymentJob($order->id))->handle(app()->make(\App\Services\KpiService::class));
            $order->refresh();
        }

        $this->assertEquals('completed', $order->status);

        // Verify Redis KPI
        $date = now()->toDateString();
        $revenue = (int) Redis::get("kpi:revenue:{$date}");
        $this->assertEquals($order->total_cents, $revenue);

        // Create and process a refund
        $refund = Refund::create(['order_id' => $order->id, 'amount_cents' => 500, 'status' => 'pending', 'external_id' => 'int-ref-1']);
        (new ProcessRefundJob($refund->id))->handle(app()->make(\App\Services\KpiService::class));

        $order->refresh();
        $this->assertEquals(500, $order->refunded_cents);

        $newRevenue = (int) Redis::get("kpi:revenue:{$date}");
        $this->assertEquals($order->total_cents - 500, $newRevenue);
    }
}
