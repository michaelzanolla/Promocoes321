<?php

namespace App\Http\Controllers;

use App\Models\PaymentEvent;
use App\Payments\ProcessPaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/asaas
 *
 * 1. Confere o token do cabeçalho (asaas-access-token). Sem token configurado, recusa tudo.
 * 2. Grava o aviso uma única vez (payment_events.provider_event_id é único): repetições são ignoradas.
 * 3. Processa na hora consultando a cobrança no Asaas. Se falhar, a conciliação tenta de novo.
 *
 * Depois de gravado, sempre responde 200: o Asaas pausa a fila de avisos quando recebe muitos erros.
 */
class AsaasWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessPaymentEvent $process): JsonResponse
    {
        $expected = (string) config('services.asaas.webhook_token');
        $received = (string) $request->header('asaas-access-token');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        $payload = $request->json()->all();
        $eventId = (string) ($payload['id'] ?? 'sem-id:'.hash('sha256', $request->getContent()));

        try {
            $event = PaymentEvent::forceCreate([
                'provider' => 'asaas',
                'provider_event_id' => mb_substr($eventId, 0, 100),
                'event_type' => mb_substr((string) ($payload['event'] ?? 'UNKNOWN'), 0, 60),
                'provider_payment_id' => $payload['payment']['id'] ?? null,
                'payload' => $payload,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Aviso já recebido.']);
        }

        $process($event);

        return response()->json(['message' => 'Recebido.']);
    }
}
