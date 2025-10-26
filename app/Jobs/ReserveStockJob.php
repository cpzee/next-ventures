<?php

namespace App\Jobs;


use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Product;
use App\Models\JobLog;
use Illuminate\Support\Facades\Log;
use function Termwind\parse;

class ReserveStockJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    // If you want to route this job to a specific queue in non-sync environments,
    // set the queue name when dispatching or configure worker routing in Horizon.

    public $orderId;

    /**
     * Create a new job instance.
     */
    public function __construct($orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info("ReserveStockJob started :" . $this->orderId);

        Log::info("ReserveStockJob started json strisssssngfffff:" . $this->orderId);
        try {
            $order = Order::find($this->orderId);

            // idempotency: if already reserved, skip
            if ($order->reserved_at) {
                Log::info("Order {$order->external_id} already reserved, skipping stock reservation.");
                JobLog::create([
                    'job_type' => 'ReserveStockJob',
                    'order_id' => $order->external_id,
                    'status' => 'skipped'
                ]);
                return;
            }

            $itemsJ = $order->metadata['items'] ?? [];
            // Normalize items: metadata may contain a JSON string or an array
            if (is_string($itemsJ)) {
                $items = json_decode($itemsJ, true);
                if (!is_array($items)) {
                    $items = [];
                }
            } elseif (is_array($itemsJ)) {
                $items = $itemsJ;
            } else {
                $items = [];
            }

            Log::info("ReserveStockJob items: " . json_encode($items));
            if (empty($items)) {
                // nothing to reserve, mark as reserved
                $order->update(['status' => 'reserved', 'reserved_at' => now()]);
                JobLog::create([
                    'job_type' => 'ReserveStockJob',
                    'order_id' => $order->external_id,
                    'status' => 'completed'
                ]);
                return;
            }

            // Perform stock checks and decrements in a transaction to avoid races
            DB::transaction(function () use ($items, $order) {
                foreach ($items as $item) {
                    $productId = $item['product_id'] ?? null;
                    $qty = (int) ($item['qty'] ?? 0);
                    if (!$productId || $qty <= 0) {
                        throw new \Exception('Invalid order item data');
                    }

                    // lock product row for update
                    $product = Product::lockForUpdate()->find($productId);
                    if (!$product) {
                        throw new \Exception("Product {$productId} not found");
                    }
                    if ($product->stock < $qty) {
                        throw new \Exception("Not enough stock for {$product->name}");
                    }
                    $product->decrement('stock', $qty);
                }

                $order->status = 'reserved';
                $order->reserved_at = now();
                $order->save();
            });

            JobLog::create([
                'job_type' => 'ReserveStockJob',
                'order_id' => $order->external_id,
                'status' => 'completed',
            ]);

            Log::info("ReserveStockJob completed for order {$order->external_id}");

        } catch (\Throwable $e) {
            Log::error("ReserveStockJob failed: " . $e->getMessage());
            // mark order as failed
            try {
                if (isset($order) && $order instanceof Order) {
                    $order->update(['status' => 'failed', 'failed_at' => now()]);
                }
            } catch (\Throwable $ee) {
                Log::error('Failed to update order status after reserve failure: ' . $ee->getMessage());
            }
            $this->fail($e);
        }
    }
}
