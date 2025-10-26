<?php

namespace App\Jobs;
use App\Models\Order;
use App\Models\NotificationHistory;
use App\Models\Customer;
use App\Models\JobLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
class SendOrderNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $orderId;
    public $channel; // 'email' or 'log'
    public function __construct($orderId, $channel = 'log')
    {
        $this->orderId = $orderId;
        $this->channel = $channel;
    }
    public function handle()
    {
        $order = Order::findOrFail($this->orderId);
        $customer = Customer::find($order->customer_id);
        $externalId = $order->external_id ?? $order->id;
        $message = "Order {$externalId} processed for " . ($customer->name ?? 'Unknown') . ", status: {$order->status}, total: {$order->total_cents}";

        Log::info($message);

        // Structured payload per requirements
        $payload = [
            'external_order_id' => $externalId,
            'customer_id' => $order->customer_id,
            'status' => $order->status,
            'total_cents' => $order->total_cents,
            'message' => $message,
        ];

        // Persist notification history
        NotificationHistory::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'channel' => $this->channel,
            'payload' => $payload,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        JobLog::create([
            'job_type' => static::class,
            'order_id' => $externalId,
            'status' => 'completed',
            'message' => $message,
        ]);
    }
}
