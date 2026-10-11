<?php

namespace App\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Integração com a API v3 do Asaas (https://docs.asaas.com).
 * Pix: cobrança + QR Code/copia e cola. Cartão: fatura hospedada no Asaas (os dados
 * do cartão nunca passam pela plataforma; 3DS e antifraude ficam com o Asaas).
 */
class AsaasGateway implements PaymentGateway
{
    public function __construct(private string $baseUrl, private ?string $apiKey) {}

    public function name(): string
    {
        return 'asaas';
    }

    public function createCharge(Order $order): Charge
    {
        $order->loadMissing('promotion');

        $data = $this->call(fn () => $this->http()->post('/payments', [
            'customer' => $this->customerId($order),
            'billingType' => $order->payment_method === PaymentMethod::CreditCard ? 'CREDIT_CARD' : 'PIX',
            'value' => $order->total_cents / 100,
            'dueDate' => now()->format('Y-m-d'),
            'description' => Str::limit("Pedido {$order->public_code} · {$order->promotion->title} · {$order->quantity} número(s)", 500),
            'externalReference' => $order->public_code,
        ]));

        $charge = $this->toCharge($data);

        if ($order->payment_method === PaymentMethod::Pix) {
            // Sem QR Code (ex.: conta sem chave Pix) a cobrança ainda pode ser paga pela fatura do Asaas.
            try {
                $qr = $this->call(fn () => $this->http()->get("/payments/{$charge->id}/pixQrCode"));

                return $charge->withPix($qr['payload'] ?? null, $qr['encodedImage'] ?? null);
            } catch (PaymentGatewayException $e) {
                report($e);
            }
        }

        return $charge;
    }

    public function fetchCharge(string $chargeId): Charge
    {
        return $this->toCharge($this->call(fn () => $this->http()->get("/payments/{$chargeId}")));
    }

    public function cancelCharge(string $chargeId): void
    {
        $this->call(fn () => $this->http()->delete("/payments/{$chargeId}"));
    }

    public function refundCharge(string $chargeId): void
    {
        $this->call(fn () => $this->http()->post("/payments/{$chargeId}/refund"));
    }

    /**
     * Reaproveita o cliente do Asaas com o mesmo CPF, ou cria um novo.
     */
    private function customerId(Order $order): string
    {
        $found = $this->call(fn () => $this->http()->get('/customers', ['cpfCnpj' => $order->buyer_cpf, 'limit' => 1]));

        if (! empty($found['data'][0]['id'])) {
            return $found['data'][0]['id'];
        }

        $created = $this->call(fn () => $this->http()->post('/customers', [
            'name' => $order->buyer_name,
            'cpfCnpj' => $order->buyer_cpf,
            'email' => $order->buyer_email,
            'mobilePhone' => $order->buyer_phone ? preg_replace('/\D/', '', $order->buyer_phone) : null,
            // O site já mostra a cobrança e envia o comprovante; evita e-mails duplicados do Asaas.
            'notificationDisabled' => true,
        ]));

        return $created['id'];
    }

    /**
     * Tradução dos status do Asaas para os do sistema.
     * https://docs.asaas.com/reference/listar-cobrancas (campo status)
     *
     * @param  array<string, mixed>  $data
     */
    private function toCharge(array $data): Charge
    {
        $providerStatus = (string) ($data['status'] ?? 'UNKNOWN');

        $status = match (true) {
            (bool) ($data['deleted'] ?? false) => PaymentStatus::Cancelled,
            in_array($providerStatus, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true) => PaymentStatus::Confirmed,
            $providerStatus === 'REFUNDED' => PaymentStatus::Refunded,
            in_array($providerStatus, ['CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL'], true) => PaymentStatus::Disputed,
            $providerStatus === 'OVERDUE' => PaymentStatus::Cancelled,
            // Estorno pedido mas ainda não concluído: o dinheiro continua recebido.
            in_array($providerStatus, ['REFUND_REQUESTED', 'REFUND_IN_PROGRESS'], true) => PaymentStatus::Confirmed,
            default => PaymentStatus::Pending, // PENDING, AWAITING_RISK_ANALYSIS, DUNNING_*
        };

        $paidAt = $data['clientPaymentDate'] ?? $data['paymentDate'] ?? $data['confirmedDate'] ?? null;

        return new Charge(
            id: (string) $data['id'],
            status: $status,
            providerStatus: $providerStatus,
            amountCents: (int) round(((float) ($data['value'] ?? 0)) * 100),
            externalReference: $data['externalReference'] ?? null,
            inRiskAnalysis: $providerStatus === 'AWAITING_RISK_ANALYSIS',
            invoiceUrl: $data['invoiceUrl'] ?? null,
            dueAt: isset($data['dueDate']) ? CarbonImmutable::parse($data['dueDate'])->endOfDay() : null,
            paidAt: $paidAt ? CarbonImmutable::parse($paidAt) : null,
        );
    }

    private function http(): PendingRequest
    {
        if (blank($this->apiKey)) {
            throw new PaymentGatewayException('Chave de API do Asaas não configurada (ASAAS_API_KEY).');
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['access_token' => $this->apiKey, 'User-Agent' => config('app.name')])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     */
    private function call(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Asaas indisponível: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $errors = implode(' ', array_column((array) $response->json('errors', []), 'description'));
            throw new PaymentGatewayException("Asaas recusou a operação (HTTP {$response->status()}): ".($errors ?: 'sem detalhes'));
        }

        return (array) $response->json();
    }
}
