<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancela um pedido que ainda aguarda pagamento e libera os números na hora.
 */
class CancelOrder
{
    public function __invoke(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::AwaitingPayment) {
                throw new DomainException('Este pedido não pode mais ser cancelado.');
            }

            OrderItem::where('order_id', $locked->id)->update(['active' => null]);
            $locked->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();
        });

        $order->refresh();
    }
}
