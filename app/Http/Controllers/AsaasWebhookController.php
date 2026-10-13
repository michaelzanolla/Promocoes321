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
        // Campos fora do formato esperado viram "desconhecido" em vez de erro 500 (o Asaas pausa a fila com muitos erros).
        $text = fn (mixed $value): ?string => is_string($value) || is_int($value) ? (string) $value : null;
        $eventId = $text($payload['id'] ?? null) ?? 'sem-id:'.hash('sha256', $request->getContent());
        $chargeId = $text($payload['payment']['id'] ?? null);

        try {
            $event = PaymentEvent::forceCreate([
                'provider' => 'asaas',
                'provider_event_id' => mb_substr($eventId, 0, 100),
                'event_type' => mb_substr($text($payload['event'] ?? null) ?? 'UNKNOWN', 0, 60),
                // ID de cobrança maior que a coluna não existe no Asaas: guardar vazio em vez de cortar.
                'provider_payment_id' => $chargeId !== null && strlen($chargeId) <= 100 ? $chargeId : null,
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
