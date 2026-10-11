<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case CreditCard = 'credit_card';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'Pix',
            self::CreditCard => 'Cartão de crédito',
        };
    }
}
