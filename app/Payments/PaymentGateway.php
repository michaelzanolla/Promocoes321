<?php

namespace App\Payments;

use App\Models\Order;

/**
 * Contrato com o provedor de pagamento. O resto do sistema só conhece esta interface,
 * para que trocar de provedor no futuro seja escrever outra implementação.
 * Implementação atual: AsaasGateway.
 */
interface PaymentGateway
{
    /** Identificador gravado em payments.provider. */
    public function name(): string;

    /**
     * Cria a cobrança do pedido na forma de pagamento escolhida (order->payment_method).
     *
     * @throws PaymentGatewayException
     */
    public function createCharge(Order $order): Charge;

    /**
     * Situação atual da cobrança, consultada direto no provedor (fonte da verdade).
     *
     * @throws PaymentGatewayException
     */
    public function fetchCharge(string $chargeId): Charge;

    /** @throws PaymentGatewayException */
    public function cancelCharge(string $chargeId): void;

    /** @throws PaymentGatewayException */
    public function refundCharge(string $chargeId): void;
}
