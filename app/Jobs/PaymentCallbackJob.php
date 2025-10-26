<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Services\KpiService;
use App\Models\JobLog;
use App\Jobs\SendOrderNotificationJob;

class PaymentCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        try {
            $order = Order::findOrFail($this->orderId);

            $log = JobLog::create([
                'job_type' => static::class,
                'order_id' => $order->external_id,
                'status' => 'queued',
                'started_at' => now(),
            ]);


            $order->update(['status' => 'completed']);

            // Dispatch notification job (use dedicated notifications queue)
            SendOrderNotificationJob::dispatch($order->id)->onQueue('notifications');

            $log->update([
                'status' => 'completed',
                'completed_at' => now(),
                'message' => 'Payment callback processed',
            ]);
        } catch (\Throwable $e) {
            // If $log exists update it, otherwise create a failure log
            if (isset($log) && $log) {
                $log->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'message' => $e->getMessage(),
                ]);
            } else {
                JobLog::create([
                    'job_type' => static::class,
                    'order_id' => $this->orderId,
                    'status' => 'failed',
                    'completed_at' => now(),
                    'message' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }
}
