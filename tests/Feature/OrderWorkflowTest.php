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

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_flow_and_refund_updates_kpis()
    {
        // ensure redis is clean
        Redis::flushdb();

        // create customer and product
        \App\Models\Customer::create(['name' => 'E2E Customer', 'email' => 'e2e@example.test']);
        $product = Product::create(['name' => 'E2E Widget', 'stock' => 5, 'price_cents' => 2000]);

        // prepare incoming order data as CSV import would produce
        $orderData = [
            'order_id' => 'e2e-ord-1',
            'customer_id' => 1,
            'total_cents' => 2000,
            'currency' => 'USD',
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                ]
            ],
        ];

        // run the ProcessOrderJob (this will create the Order and dispatch ReserveStockJob->SimulatePaymentJob chain)
        $job = new ProcessOrderJob($orderData);
        $job->handle();

        // verify order created
        $order = Order::where('external_id', $orderData['order_id'])->first();
        $this->assertNotNull($order, 'Order should be created');

        // In the sync queue mode the chain may not be executed automatically; run chained jobs explicitly if needed
        if ($order->status !== 'completed') {
            $reserve = new \App\Jobs\ReserveStockJob($order->id);
            $reserve->handle();
            $pay = new \App\Jobs\SimulatePaymentJob($order->id);
            $pay->handle(app()->make(\App\Services\KpiService::class));
            $order->refresh();
        }

        $this->assertEquals('completed', $order->status, 'Order should be completed after simulated payment');

        // product stock should have been decremented by 2
        $product->refresh();
        $this->assertEquals(3, $product->stock);

        // KPI checks in Redis — use current date because completed_at may not be set in sync mode
        $date = now()->toDateString();
        $revenue = (int) Redis::get("kpi:revenue:{$date}");
        $this->assertEquals($order->total_cents, $revenue, 'Revenue KPI should match order total');

        $leaderScore = (int) Redis::zscore('leaderboard:customers', 'customer:' . $order->customer_id);
        $this->assertEquals($order->total_cents, $leaderScore, 'Leaderboard score should reflect customer revenue');

        // Now simulate a refund of 500 cents
        $refund = Refund::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'status' => 'pending',
            'external_id' => 'refund-e2e-1',
        ]);

        // process refund
        $refundJob = new ProcessRefundJob($refund->id);
        $refundJob->handle(app()->make(\App\Services\KpiService::class));

        $order->refresh();
        $this->assertEquals(500, $order->refunded_cents, 'Order refunded_cents should be updated');

        // KPI revenue decreased by 500
        $newRevenue = (int) Redis::get("kpi:revenue:{$date}");
        $this->assertEquals($order->total_cents - 500, $newRevenue);

        // leaderboard adjusted
        $newScore = (int) Redis::zscore('leaderboard:customers', 'customer:' . $order->customer_id);
        $this->assertEquals($order->total_cents - 500, $newScore);
    }
}
