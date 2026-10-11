<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayException;
use App\Payments\ReconcilePayments;
use App\Payments\StartPayment;
use App\Rules\Cpf;
use App\Services\CancelOrder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function show(Request $request, string $code): View|RedirectResponse
    {
        $order = Order::where('public_code', $code)->with('promotion', 'items')->firstOrFail();

        // Link secreto do pedido (enviado por e-mail): libera o acesso nesta sessão e limpa a URL.
        if ($request->filled('t')) {
            abort_unless($order->matchesToken((string) $request->query('t')), 404);
            $order->grantAccessInSession($request->session());

            return redirect()->route('orders.show', $order->public_code);
        }

        $this->authorizeAccess($request, $order);

        return view('site.order', ['order' => $order]);
    }

    public function identify(Request $request, string $code): RedirectResponse
    {
        $order = Order::where('public_code', $code)->with('promotion')->firstOrFail();
        $this->authorizeAccess($request, $order);

        if (! $order->isPayable()) {
            return back()->withErrors(['order' => 'Este pedido não aceita mais alterações.']);
        }

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'min:3', 'max:255'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_cpf' => ['required', 'string', new Cpf],
            'buyer_phone' => ['nullable', 'string', 'max:20'],
            'payment_method' => ['required', Rule::in($order->promotion->payment_methods)],
            'accept_terms' => ['accepted'],
        ], [
            'payment_method.in' => 'Esta forma de pagamento não está disponível para esta promoção.',
            'accept_terms.accepted' => 'Para continuar, aceite as regras da promoção e os termos de uso.',
        ]);

        $order->forceFill([
            'buyer_name' => $data['buyer_name'],
            'buyer_email' => mb_strtolower($data['buyer_email']),
            'buyer_cpf' => Cpf::digits($data['buyer_cpf']),
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'payment_method' => PaymentMethod::from($data['payment_method']),
            'terms_accepted_at' => now(),
        ])->save();

        return redirect()->route('orders.show', $order->public_code)->with('status', 'Dados confirmados.');
    }

    public function cancel(Request $request, string $code, CancelOrder $cancelOrder, PaymentGateway $gateway): RedirectResponse
    {
        $order = Order::where('public_code', $code)->firstOrFail();
        $this->authorizeAccess($request, $order);

        try {
            $cancelOrder($order);
        } catch (DomainException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        // Tenta já invalidar a cobrança no provedor; se falhar, a conciliação agendada cancela depois.
        foreach ($order->payments()->where('status', PaymentStatus::Pending)->get() as $payment) {
            try {
                $gateway->cancelCharge($payment->provider_payment_id);
            } catch (PaymentGatewayException $e) {
                report($e);
            }
        }

        return redirect()->route('orders.show', $order->public_code)->with('status', 'Pedido cancelado. Os números foram liberados.');
    }

    /**
     * Gera a cobrança (Pix ou fatura do cartão). Cartão: leva para a fatura do provedor.
     * Voltar da fatura não confirma nada: a confirmação vem só do provedor.
     */
    public function pay(Request $request, string $code, StartPayment $startPayment): RedirectResponse
    {
        $order = Order::where('public_code', $code)->firstOrFail();
        $this->authorizeAccess($request, $order);

        try {
            $payment = $startPayment($order);
        } catch (DomainException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        } catch (PaymentGatewayException $e) {
            report($e);

            return back()->withErrors(['order' => 'Não conseguimos falar com o sistema de pagamento agora. Seus números continuam reservados; tente de novo em instantes.']);
        }

        if ($payment->method === PaymentMethod::CreditCard && $payment->invoice_url) {
            return redirect()->away($payment->invoice_url);
        }

        return redirect()->route('orders.show', $order->public_code);
    }

    /**
     * Situação do pedido para a página atualizar sozinha. Enquanto há cobrança em aberto,
     * consulta o provedor no máximo a cada 8 s (não depende do webhook).
     */
    public function status(Request $request, string $code, ReconcilePayments $reconcile): JsonResponse
    {
        $order = Order::where('public_code', $code)->firstOrFail();
        $this->authorizeAccess($request, $order);

        $payment = $order->payments()->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Failed])->latest('id')->first();

        if ($payment && ($payment->last_checked_at === null || $payment->last_checked_at->lte(now()->subSeconds(8)))) {
            try {
                $reconcile->check($payment);
            } catch (PaymentGatewayException $e) {
                report($e);
            }
            $order->refresh();
        }

        $latest = $order->payments()->latest('id')->first();

        return response()->json([
            'order' => $order->status->value,
            'payment' => $latest?->status->value,
        ]);
    }

    /**
     * 404 em vez de 403: não confirma para estranhos que o código existe.
     */
    private function authorizeAccess(Request $request, Order $order): void
    {
        abort_unless($order->isAccessibleBy($request->user(), $request->session()), 404);
    }
}
