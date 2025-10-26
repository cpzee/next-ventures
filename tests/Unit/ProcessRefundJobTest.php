<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\ProcessRefundJob;
use App\Models\Refund;
use App\Models\Order;
use App\Models\Product;
use App\Services\KpiService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Carbon;

class ProcessRefundJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_refund_is_idempotent()
    {
        // create a customer and order
        \App\Models\Customer::create(['name' => 'Refund Customer', 'email' => 'r@example.test']);
        $order = Order::create([
            'external_id' => 'ord_2',
            'customer_id' => 1,
            'total_cents' => 1000,
            'metadata' => [],
        ]);

        // simulate order completed so KPI has a value
        $order->completed_at = now();
        $order->save();

        // record revenue in redis
        Redis::set('kpi:revenue:' . $order->completed_at->toDateString(), 1000);
        Redis::zincrby('leaderboard:customers', 1000, 'customer:' . $order->customer_id);

        // create refund
        $refund = Refund::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'status' => 'pending',
            'external_id' => 'r1'
        ]);

        // process refund twice
        $job = new ProcessRefundJob($refund->id);
        $job->handle(app(KpiService::class));

        $order->refresh();
        $this->assertEquals(500, $order->refunded_cents);

        // run again - should be no-op
        $job2 = new ProcessRefundJob($refund->id);
        $job2->handle(app(KpiService::class));

        $order->refresh();
        $this->assertEquals(500, $order->refunded_cents, 'Refund should not be applied twice');

        // redis revenue should be decreased by 500 once
        $dateKey = Carbon::parse($order->completed_at)->toDateString();
        $value = (int) Redis::get('kpi:revenue:' . $dateKey);
        $this->assertEquals(500, $value);
    }
}
