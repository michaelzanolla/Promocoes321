<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Só o dono do pedido ou um administrador. Compradores convidados acessam
     * pelo link secreto do pedido (Order::isAccessibleBy).
     */
    public function view(User $user, Order $order): bool
    {
        return $user->isAdmin() || $order->user_id === $user->id;
    }

    /** Pedidos mudam só pelas ações controladas (pagamento, cancelamento, reembolso). */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
