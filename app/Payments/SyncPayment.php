<?php

namespace App\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aplica no pagamento e no pedido a situação informada pelo provedor.
 *
 * Regras (docs/fase-1-planejamento.md, seções 5 e 6):
 * - Só chega aqui o que foi consultado direto no provedor (nunca o corpo do webhook ou um redirecionamento).
 * - Idempotente: aplicar a mesma situação duas vezes não muda nada.
 * - O status só anda para frente; avisos fora de ordem são ignorados.
 * - Pagamento confirmado depois de os números irem para outra pessoa nunca troca números sozinho:
 *   o pedido vai para "em análise" e um administrador decide (normalmente, reembolso).
 */
class SyncPayment
{
    /** Eventos que indicam recusa mesmo com a cobrança ainda "pendente" no provedor. */
    private const REFUSAL_EVENTS = ['PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', 'PAYMENT_REPROVED_BY_RISK_ANALYSIS'];

    public function apply(Payment $payment, Charge $charge, ?string $eventType = null): void
    {
        DB::transaction(function () use ($payment, $charge, $eventType) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();

            if ($charge->id !== $payment->provider_payment_id || $charge->externalReference !== $order->public_code) {
                Log::warning('Pagamento não confere com o pedido', ['payment' => $payment->id, 'charge' => $charge->id, 'ref' => $charge->externalReference]);
                $this->review($order, 'O pagamento informado pelo provedor não corresponde a este pedido.');

                return;
            }

            $new = $charge->status;
            if ($new === PaymentStatus::Pending && in_array($eventType, self::REFUSAL_EVENTS, true)) {
                $new = PaymentStatus::Failed;
            }

            $payment->forceFill(['provider_status' => $charge->providerStatus, 'last_checked_at' => now()]);

            if (! $this->canMove($payment->status, $new)) {
                $payment->save();

                return;
            }

            $payment->forceFill([
                'status' => $new,
                'paid_at' => $new === PaymentStatus::Confirmed ? ($charge->paidAt ?? now()) : $payment->paid_at,
                'refunded_at' => $new === PaymentStatus::Refunded ? now() : $payment->refunded_at,
                'failure_reason' => $new === PaymentStatus::Failed ? ($charge->failureReason ?? 'Pagamento recusado pelo provedor.') : null,
            ])->save();

            match ($new) {
                PaymentStatus::Confirmed => $this->confirm($order, $charge),
                PaymentStatus::Refunded => $this->refund($order),
                PaymentStatus::Disputed => $this->review($order, 'O comprador abriu uma contestação do pagamento no cartão.'),
                PaymentStatus::Pending => $charge->inRiskAnalysis && $order->status === OrderStatus::AwaitingPayment
                    ? $order->forceFill(['status' => OrderStatus::Processing])->save()
                    : null,
                // Recusado ou cancelado: o pedido em análise de risco volta a aguardar; o prazo da reserva segue valendo.
                PaymentStatus::Failed, PaymentStatus::Cancelled => $order->status === OrderStatus::Processing
                    ? $order->forceFill(['status' => OrderStatus::AwaitingPayment])->save()
                    : null,
            };
        });
    }

    /**
     * Transições permitidas do pagamento. O mesmo status ou um retrocesso não fazem nada.
     */
    private function canMove(PaymentStatus $from, PaymentStatus $to): bool
    {
        return match ($from) {
            PaymentStatus::Pending => $to !== PaymentStatus::Pending,
            PaymentStatus::Failed => in_array($to, [PaymentStatus::Pending, PaymentStatus::Confirmed, PaymentStatus::Cancelled], true),
            PaymentStatus::Cancelled => $to === PaymentStatus::Confirmed, // pagamento tardio
            PaymentStatus::Confirmed => in_array($to, [PaymentStatus::Refunded, PaymentStatus::Disputed], true),
            PaymentStatus::Disputed => in_array($to, [PaymentStatus::Confirmed, PaymentStatus::Refunded], true),
            PaymentStatus::Refunded => false,
        };
    }

    private function confirm(Order $order, Charge $charge): void
    {
        if ($charge->amountCents !== $order->total_cents) {
            $this->review($order, 'O valor pago é diferente do total do pedido.');

            return;
        }

        if ($order->status === OrderStatus::Confirmed) {
            return;
        }

        if (in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true)) {
            // Pagamento depois que a reserva acabou: tenta pegar os números de volta.
            // Se algum já é de outra pessoa, o banco recusa e o pedido vai para análise.
            try {
                DB::transaction(fn () => OrderItem::where('order_id', $order->id)->update(['active' => 1]));
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                $this->review($order, 'Pagamento recebido depois que a reserva acabou, e algum número já foi para outra pessoa. Reembolse ou combine outros números com o comprador.');

                return;
            }
        } elseif (! in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::Processing], true)) {
            $this->review($order, 'Pagamento confirmado para um pedido que estava como "'.$order->status->label().'".');

            return;
        }

        $order->forceFill([
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => now(),
            'receipt_code' => $order->receipt_code ?? $this->newReceiptCode(),
        ])->save();
    }

    /** Números de pedido reembolsado continuam presos: não voltam à venda sozinhos. */
    private function refund(Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Confirmed, OrderStatus::NeedsReview], true)) {
            $order->forceFill(['status' => OrderStatus::Refunded, 'refunded_at' => now()])->save();
        }
    }

    private function review(Order $order, string $reason): void
    {
        $order->forceFill(['status' => OrderStatus::NeedsReview, 'review_reason' => $reason])->save();
    }

    private function newReceiptCode(): string
    {
        do {
            $code = Str::upper(Str::random(16));
        } while (Order::where('receipt_code', $code)->exists());

        return $code;
    }
}
