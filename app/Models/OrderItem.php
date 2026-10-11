<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public $timestamps = false;

    /**
     * Números efetivamente ocupados de uma promoção: item ativo cujo pedido não é uma
     * reserva vencida (ainda não limpa pelo agendador). Traz orders.status para diferenciar
     * reservado de vendido.
     *
     * @param  Builder<OrderItem>  $query
     */
    public function scopeTaken(Builder $query, int $promotionId): void
    {
        $query->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.promotion_id', $promotionId)
            ->where('order_items.active', 1)
            ->where(fn (Builder $q) => $q
                ->where('orders.status', '!=', OrderStatus::AwaitingPayment)
                ->orWhere('orders.reserved_until', '>', now()));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
