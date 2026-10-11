<?php

namespace App\Enums;

enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Processing = 'processing';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Aguardando pagamento',
            self::Processing => 'Pagamento em processamento',
            self::Confirmed => 'Confirmado',
            self::Expired => 'Expirado',
            self::Cancelled => 'Cancelado',
            self::Refunded => 'Reembolsado',
            self::NeedsReview => 'Em análise',
        };
    }
}
