<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Expira pedidos aguardando pagamento cujo prazo de reserva passou e libera os números.
 * Pedidos "em processamento" (cartão em análise) nunca expiram aqui.
 *
 * Chamado de dois lugares: pela própria reserva (expiração preguiçosa, só dos números
 * disputados) e pelo agendador a cada minuto (limpeza geral). A corretude não depende do agendador.
 */
class ExpireOrders
{
    /**
     * Expira os pedidos vencidos indicados, travando as linhas. Deve rodar dentro de uma transação.
     *
     * @param  list<int>  $orderIds
     */
    public function expireLocked(array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        $expired = Order::whereKey($orderIds)
            ->where('status', OrderStatus::AwaitingPayment)
            ->where('reserved_until', '<=', now())
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        if ($expired === []) {
            return 0;
        }

        OrderItem::whereIn('order_id', $expired)->update(['active' => null]);
        Order::whereKey($expired)->update(['status' => OrderStatus::Expired, 'expired_at' => now()]);

        return count($expired);
    }

    /**
     * Limpeza geral: expira todos os pedidos vencidos, em lotes.
     */
    public function expireAllDue(): int
    {
        $total = 0;

        Order::where('status', OrderStatus::AwaitingPayment)
            ->where('reserved_until', '<=', now())
            ->select('id')
            ->chunkById(200, function ($orders) use (&$total) {
                $total += DB::transaction(fn () => $this->expireLocked(array_values($orders->pluck('id')->map(fn ($id) => (int) $id)->all())));
            });

        return $total;
    }
}
