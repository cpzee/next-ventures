<?php

namespace App\Jobs;
use App\Models\Refund;
use App\Models\Order;
use App\Services\KpiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
class ProcessRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $refundId;
    public function __construct($refundId)
    {
        $this->refundId = $refundId;
    }
    public function handle(KpiService $kpi)
    {
        DB::transaction(function () use ($kpi) {
            // lock the refund row to avoid races
            $refund = Refund::lockForUpdate()->find($this->refundId);
            if (!$refund) {
                return;
            }
            // Idempotency: if already processed, exit
            if ($refund->status === 'processed') {
                return;
            }

            // lock the order row for update
            $order = Order::lockForUpdate()->find($refund->order_id);
            if (!$order) {
                $refund->update(['status' => 'failed']);
                return;
            }
            // Calculate refundable amount guard: do not refund more than order total
            $refundableRemaining = max(0, $order->total_cents - ($order->refunded_cents ?? 0));
            $amount = min($refund->amount_cents, $refundableRemaining);
            if ($amount <= 0) {
                $refund->update(['status' => 'failed']);
                return;
            }
            // Apply refund: update order refunded_cents and possibly status
            $order->refunded_cents = ($order->refunded_cents ?? 0) + $amount;
            if ($order->refunded_cents >= $order->total_cents) {
                $order->status = 'refunded';
            }
            $order->save();
            // Mark refund processed
            $refund->update(['status' => 'processed']);
            // Update KPIs (subtract revenue) and leaderboard
            $kpi->applyRefund($order, $amount);
        });
    }
}
