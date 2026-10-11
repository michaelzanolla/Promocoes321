<?php

namespace App\Payments;

use RuntimeException;

/**
 * Falha ao falar com o provedor (fora do ar, recusa, resposta inesperada).
 * A mensagem é técnica e vai para o log; o usuário vê um texto amigável.
 */
class PaymentGatewayException extends RuntimeException {}
