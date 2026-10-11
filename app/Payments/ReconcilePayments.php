<?php

namespace App\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Throwable;

/**
 * Conciliação com o provedor (roda a cada minuto pelo agendador).
 *
 * - Consulta cobranças em aberto: confirma pagamentos mesmo sem webhook (ou se um aviso se perdeu).
 * - Cancela no provedor as cobranças de pedidos que expiraram ou foram cancelados,
 *   para que o QR Code pare de aceitar pagamento.
 * - Reprocessa avisos de webhook que falharam.
 */
class ReconcilePayments
{
    public function __construct(
        private PaymentGateway $gateway,
        private SyncPayment $sync,
        private ProcessPaymentEvent $processEvent,
    ) {}

    /**
     * @return array{checked: int, cancelled: int, events: int, errors: int}
     */
    public function run(int $minSecondsBetweenChecks = 60): array
    {
        $stats = ['checked' => 0, 'cancelled' => 0, 'events' => 0, 'errors' => 0];

        Payment::query()
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Failed])
            ->where('created_at', '>=', now()->subDays(3))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subSeconds($minSecondsBetweenChecks)))
            ->with('order')
            ->orderBy('last_checked_at')
            ->limit(100)
            ->get()
            ->each(function (Payment $payment) use (&$stats) {
                try {
                    if (in_array($payment->order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true)) {
                        $charge = $this->gateway->fetchCharge($payment->provider_payment_id);
                        if ($charge->status === PaymentStatus::Pending) {
                            $this->gateway->cancelCharge($payment->provider_payment_id);
                            $stats['cancelled']++;
                        }
                    }

                    $this->check($payment);
                    $stats['checked']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $payment->forceFill(['last_checked_at' => now()])->save();
                    report($e);
                }
            });

        PaymentEvent::whereNull('processed_at')
            ->where('received_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (PaymentEvent $event) use (&$stats) {
                ($this->processEvent)($event);
                $stats['events']++;
            });

        return $stats;
    }

    /** Consulta uma cobrança no provedor e aplica o resultado. */
    public function check(Payment $payment): void
    {
        $this->sync->apply($payment, $this->gateway->fetchCharge($payment->provider_payment_id));
    }
}
