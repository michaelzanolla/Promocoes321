<?php

namespace App\Support;

class Format
{
    /** 1500 → "R$ 15,00" */
    public static function brl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
