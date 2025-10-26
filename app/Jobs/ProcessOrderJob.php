<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;


class ProcessOrderJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public $orderData;

    /**
     * Create a new job instance.
     */
    public function __construct(array $orderData)
    {
        $this->orderData = $orderData;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Basic required fields: order_id (external id), customer_id, total_cents
        if (empty($this->orderData['order_id'])) {
            return;
        }

        // Idempotent create/update using external_id field
        $order = Order::updateOrCreate(
            ['external_id' => $this->orderData['order_id']],
            [
                'customer_id' => $this->orderData['customer_id'] ?? 0,
                'total_cents' => (int) ($this->orderData['total_cents'] ?? 0),
                'currency' => $this->orderData['currency'] ?? 'USD',
                'status' => 'pending',
                'metadata' => $this->orderData,
            ]
        );

        // Dispatch ReserveStock -> SimulatePayment chain using the order's PK
        ReserveStockJob::withChain([
            new SimulatePaymentJob($order->id),
        ])->dispatch($order->id);
    }
}
