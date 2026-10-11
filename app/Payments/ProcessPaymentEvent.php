<?php

namespace App\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Throwable;

/**
 * Processa um aviso de webhook já gravado em payment_events.
 * O corpo do aviso só diz QUAL cobrança mudou; a situação é sempre consultada no provedor.
 */
class ProcessPaymentEvent
{
    public function __construct(private PaymentGateway $gateway, private SyncPayment $sync) {}

    public function __invoke(PaymentEvent $event): void
    {
        try {
            $chargeId = $event->provider_payment_id;

            if (! $chargeId) {
                $this->finish($event, 'Aviso sem identificador de cobrança (ignorado).');

                return;
            }

            $charge = $this->gateway->fetchCharge($chargeId);
            $payment = Payment::where('provider', $event->provider)->where('provider_payment_id', $chargeId)->first()
                ?? $this->adoptOrphan($charge);

            if (! $payment) {
                $this->finish($event, 'Cobrança sem pedido correspondente nesta plataforma (ignorada).');

                return;
            }

            $this->sync->apply($payment, $charge, $event->event_type);
            $this->finish($event);
        } catch (Throwable $e) {
            // Fica sem processed_at: a conciliação agendada tenta de novo.
            $event->forceFill(['error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            report($e);
        }
    }

    /**
     * Cobrança criada no provedor cujo registro local não chegou a ser gravado (ex.: queda
     * entre a criação e a gravação). Recupera pelo código do pedido em externalReference.
     */
    private function adoptOrphan(Charge $charge): ?Payment
    {
        $order = $charge->externalReference ? Order::where('public_code', $charge->externalReference)->first() : null;

        if (! $order) {
            return null;
        }

        return Payment::forceCreate([
            'order_id' => $order->id,
            'provider' => $this->gateway->name(),
            'provider_payment_id' => $charge->id,
            'method' => $order->payment_method ?? PaymentMethod::Pix,
            'amount_cents' => $charge->amountCents,
            'status' => PaymentStatus::Pending,
            'provider_status' => $charge->providerStatus,
            'invoice_url' => $charge->invoiceUrl,
        ]);
    }

    private function finish(PaymentEvent $event, ?string $note = null): void
    {
        $event->forceFill(['processed_at' => now(), 'error' => $note])->save();
    }
}
