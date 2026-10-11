<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Algum número pedido já está reservado ou vendido. Nada foi reservado.
 */
class NumbersUnavailable extends RuntimeException
{
    /**
     * @param  list<int>  $numbers  números em conflito
     */
    public function __construct(public readonly array $numbers)
    {
        parent::__construct('Números indisponíveis: '.implode(', ', $numbers));
    }
}
