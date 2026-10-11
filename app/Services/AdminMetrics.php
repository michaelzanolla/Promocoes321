<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PromotionPhase;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indicadores do painel. Regra central: reserva e pagamento pendente nunca contam como receita.
 */
class AdminMetrics
{
    /**
     * @return array{total: int, open: int, finished: int}
     */
    public function promotions(): array
    {
        return [
            'total' => Promotion::count(),
            'open' => Promotion::inPhase(PromotionPhase::Open)->count(),
            'finished' => Promotion::inPhase(PromotionPhase::Closed)->count() + Promotion::inPhase(PromotionPhase::Drawn)->count(),
        ];
    }

    /**
     * @return array{reserved: int, sold: int}
     */
    public function numbers(): array
    {
        $active = fn (array $statuses, bool $onlyValid = false) => OrderItem::where('active', 1)
            ->whereHas('order', fn (Builder $q) => $q->whereIn('status', $statuses)
                ->when($onlyValid, fn (Builder $q) => $q->where('reserved_until', '>', now())))
            ->count();

        return [
            'reserved' => $active([OrderStatus::AwaitingPayment, OrderStatus::Processing], true),
            'sold' => $active([OrderStatus::Confirmed]),
        ];
    }

    /**
     * @return array{pending: int, confirmed: int, cancelled_or_expired: int, needs_review: int}
     */
    public function orders(): array
    {
        $count = fn (array $statuses) => Order::whereIn('status', $statuses)->count();

        return [
            'pending' => $count([OrderStatus::AwaitingPayment, OrderStatus::Processing]),
            'confirmed' => $count([OrderStatus::Confirmed]),
            'cancelled_or_expired' => $count([OrderStatus::Cancelled, OrderStatus::Expired]),
            'needs_review' => $count([OrderStatus::NeedsReview]),
        ];
    }

    /**
     * Valores em centavos.
     * - received: pagamentos confirmados pelo provedor e não reembolsados
     * - refunded: pagamentos reembolsados
     * - gross: tudo o que chegou a ser pago (received + refunded)
     * - pending: pedidos com reserva válida aguardando pagamento (não é receita)
     *
     * @return array{gross: int, received: int, refunded: int, pending: int}
     */
    public function money(): array
    {
        $received = (int) Payment::where('status', PaymentStatus::Confirmed)->sum('amount_cents');
        $refunded = (int) Payment::where('status', PaymentStatus::Refunded)->sum('amount_cents');

        return [
            'gross' => $received + $refunded,
            'received' => $received,
            'refunded' => $refunded,
            'pending' => (int) Order::whereIn('status', [OrderStatus::AwaitingPayment, OrderStatus::Processing])
                ->where('reserved_until', '>', now())->sum('total_cents'),
        ];
    }
}
