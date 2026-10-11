<?php

namespace App\Policies;

use App\Enums\PromotionStatus;
use App\Models\Promotion;
use App\Models\User;

/**
 * Usada pelo painel. Só administradores chegam até aqui (User::canAccessPanel).
 */
class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Promoções com resultado publicado ou canceladas ficam congeladas. */
    public function update(User $user, Promotion $promotion): bool
    {
        return $user->isAdmin()
            && ! in_array($promotion->status, [PromotionStatus::Drawn, PromotionStatus::Cancelled], true);
    }

    /** Só rascunhos sem nenhum pedido podem ser apagados. */
    public function delete(User $user, Promotion $promotion): bool
    {
        return $user->isAdmin() && $promotion->status === PromotionStatus::Draft && ! $promotion->hasOrders();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
