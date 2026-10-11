<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Rules\Cpf;
use App\Services\CancelOrder;
use DomainException;
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

    public function cancel(Request $request, string $code, CancelOrder $cancelOrder): RedirectResponse
    {
        $order = Order::where('public_code', $code)->firstOrFail();
        $this->authorizeAccess($request, $order);

        try {
            $cancelOrder($order);
        } catch (DomainException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order->public_code)->with('status', 'Pedido cancelado. Os números foram liberados.');
    }

    /**
     * 404 em vez de 403: não confirma para estranhos que o código existe.
     */
    private function authorizeAccess(Request $request, Order $order): void
    {
        abort_unless($order->isAccessibleBy($request->user(), $request->session()), 404);
    }
}
