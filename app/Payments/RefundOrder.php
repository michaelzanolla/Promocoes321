<?php

namespace App\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Order;
use DomainException;

/**
 * Reembolso integral pedido por um administrador. O pedido só vira "reembolsado" quando o
 * provedor confirma o estorno (consultado logo em seguida e, se demorar, pela conciliação).
 */
class RefundOrder
{
    public function __construct(private PaymentGateway $gateway, private ReconcilePayments $reconcile) {}

    public function __invoke(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::NeedsReview], true)) {
            throw new DomainException('Só pedidos confirmados ou em análise podem ser reembolsados.');
        }

        $payment = $order->payments()->where('status', PaymentStatus::Confirmed)->latest('id')->first();

        if (! $payment) {
            throw new DomainException('Este pedido não tem pagamento confirmado para reembolsar.');
        }

        $this->gateway->refundCharge($payment->provider_payment_id);
        AuditLog::record('order.refund_requested', $order, ['payment' => $payment->provider_payment_id, 'amount_cents' => $payment->amount_cents]);

        $this->reconcile->check($payment);
    }
}
