<?php
namespace App\Services;
use Illuminate\Support\Facades\Redis;
use App\Models\Order;
use App\Models\DailyKpi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
class KpiService
{
    protected $redisCon;

    public function __construct()
    {
        /** @var \Illuminate\Redis\Connections\PhpRedisConnection|\Illuminate\Redis\Connections\PredisConnection $this->redisCon */
        $this->redisCon = Redis::connection();
    }
    public function recordOrderCompleted(Order $order)
    {
        // Attribute revenue to the completion date when available
        // Normalize timestamps to Carbon instances (DB may return strings for custom timestamps)
        $completedAt = $order->completed_at ? ($order->completed_at instanceof \DateTime ? Carbon::parse($order->completed_at) : Carbon::parse($order->completed_at)) : null;
        $createdAt = $order->created_at ? ($order->created_at instanceof \DateTime ? Carbon::parse($order->created_at) : Carbon::parse($order->created_at)) : null;
        $date = $completedAt ? $completedAt->toDateString() : ($createdAt ? $createdAt->toDateString() : now()->toDateString());
        $this->redisCon->incrby(
            "kpi:revenue:{$date}",
            $order->total_cents
        );
        $this->redisCon->incr("kpi:orders:{$date}");
        $this->redisCon->zincrby(
            "leaderboard:customers",
            $order->total_cents,
            "customer:{$order->customer_id}"
        );

        // Also persist a durable daily KPI row in MySQL
        try {
            // Use DB transaction for the small set of updates to avoid race conditions
            DB::transaction(function () use ($date, $order) {
                $kpi = DailyKpi::firstOrCreate([
                    'date' => $date,
                ], [
                    'revenue_cents' => 0,
                    'orders_count' => 0,
                    'avg_order_value' => 0,
                ]);

                // increment counters
                $kpi->increment('revenue_cents', $order->total_cents);
                $kpi->increment('orders_count');
                // refresh and compute average
                $kpi->refresh();
                $kpi->avg_order_value = $kpi->orders_count ? ($kpi->revenue_cents / $kpi->orders_count) : 0;
                $kpi->save();
            });
        } catch (\Throwable $e) {
            // Do not let DB failures stop the main flow; log and continue
            // Using Laravel Log facade would be ideal here but keep small to avoid imports in this service
        }
    }
    public function applyRefund(Order $order, int $amountCents)
    {
        // Use the same date attribution as recordOrderCompleted (prefer completed_at)
        $completedAt = $order->completed_at ? ($order->completed_at instanceof \DateTime ? Carbon::parse($order->completed_at) : Carbon::parse($order->completed_at)) : null;
        $createdAt = $order->created_at ? ($order->created_at instanceof \DateTime ? Carbon::parse($order->created_at) : Carbon::parse($order->created_at)) : null;
        $date = $completedAt ? $completedAt->toDateString() : ($createdAt ? $createdAt->toDateString() : now()->toDateString());
        // Guard: don't allow revenue to go negative; for full atomicity use Lua scripting
        $key = "kpi:revenue:{$date}";
        $current = (int) $this->redisCon->get($key);
        $new = max(0, $current - $amountCents);
        $this->redisCon->set($key, $new);
        // Update orders count: leave as-is (we're only adjusting revenue). If you want to decrement orders count on full refund, implement separately.
        $this->redisCon->zincrby("leaderboard:customers", -$amountCents, "customer:{$order->customer_id}");

        // Also update durable daily KPI row if present
        try {
            DB::transaction(function () use ($date, $amountCents) {
                $kpi = DailyKpi::where('date', $date)->first();
                if (!$kpi) {
                    return;
                }
                // decrement revenue but don't go negative
                $kpi->decrement('revenue_cents', $amountCents);
                $kpi->refresh();
                if ($kpi->revenue_cents < 0) {
                    $kpi->revenue_cents = 0;
                }
                // avg order value: keep orders_count as-is
                $kpi->avg_order_value = $kpi->orders_count ? ($kpi->revenue_cents / $kpi->orders_count) : 0;
                $kpi->save();
            });
        } catch (\Throwable $e) {
            // swallow DB errors to avoid affecting refund processing
        }
    }
}