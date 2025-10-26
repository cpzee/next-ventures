<?php

namespace App\Jobs;

use App\Models\JobLog;
use App\Services\KpiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use App\Models\Order;
use App\Jobs\SendOrderNotificationJob;

class SimulatePaymentJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public $orderId;

    /**
     * Create a new job instance.
     */
    public function __construct($orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(KpiService $kpi)
    {
        $order = Order::findOrFail($this->orderId);

        $log = JobLog::create([
            'job_type' => static::class,
            'order_id' => $order->external_id,
            'status' => 'queued',
            'started_at' => now(),
        ]);

        try {
            // Simulate payment success
            $order->update(['status' => 'completed', 'completed_at' => now()]);

            // Update KPIs using KpiService (date-scoped keys using completed_at)
            $kpi->recordOrderCompleted($order);

            // Dispatch notification job (queued, but do not force onQueue here to keep sync mode safe)
            SendOrderNotificationJob::dispatch($order->id);

            $log->update([
                'status' => 'completed',
                'completed_at' => now(),
                'message' => 'Payment simulated successfully',
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'completed_at' => now(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}