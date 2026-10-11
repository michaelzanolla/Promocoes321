<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Gera a cobrança do pedido no provedor. Idempotente: clique repetido, recarregar a
 * página ou duas abas devolvem a mesma cobrança pendente em vez de criar outra.
 */
class StartPayment
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * @throws DomainException quando o pedido não pode ser pago
     * @throws PaymentGatewayException quando o provedor falha
     */
    public function __invoke(Order $order): Payment
    {
        return DB::transaction(function () use ($order) {
            // A trava no pedido serializa pedidos simultâneos de cobrança do mesmo pedido.
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isPayable()) {
                throw new DomainException('Este pedido não aceita mais pagamento. Confira a situação na página do pedido.');
            }
            if (! $order->isIdentified()) {
                throw new DomainException('Confirme seus dados antes de gerar o pagamento.');
            }

            $open = $order->payments()
                ->where('method', $order->payment_method)
                ->where('status', PaymentStatus::Pending)
                ->latest('id')
                ->first();

            if ($open) {
                return $open;
            }

            $charge = $this->gateway->createCharge($order);

            return Payment::forceCreate([
                'order_id' => $order->id,
                'provider' => $this->gateway->name(),
                'provider_payment_id' => $charge->id,
                'method' => $order->payment_method,
                'amount_cents' => $order->total_cents,
                'status' => PaymentStatus::Pending,
                'provider_status' => $charge->providerStatus,
                'pix_payload' => $charge->pixPayload,
                'pix_qr_base64' => $charge->pixQrBase64,
                'invoice_url' => $charge->invoiceUrl,
                'due_at' => $charge->dueAt,
                'last_checked_at' => now(),
            ]);
        });
    }
}
