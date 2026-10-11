<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Só o dono do pedido ou um administrador. Compradores convidados acessam
     * pelo link secreto do pedido, verificado à parte (Fase 5).
     */
    public function view(User $user, Order $order): bool
    {
        return $user->isAdmin() || $order->user_id === $user->id;
    }
}
