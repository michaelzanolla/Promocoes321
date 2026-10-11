<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Qr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Comprovante. O conteúdo é sempre montado a partir do banco: alterar o HTML ou o PDF
 * não muda o que a página pública de verificação mostra.
 *
 * - /comprovante/{receipt_code}: verificação pública. Sem CPF, e-mail ou telefone; nome abreviado.
 * - /pedido/{code}/comprovante (e .pdf): versão do comprador, com o nome completo.
 */
class ReceiptController extends Controller
{
    public function verify(string $receiptCode): View
    {
        $order = Order::where('receipt_code', mb_strtoupper($receiptCode))->with('promotion', 'items', 'payments')->firstOrFail();

        return view('site.receipt', ['receipt' => $this->data($order, public: true), 'public' => true]);
    }

    public function show(Request $request, string $code): View
    {
        $order = $this->ownedOrder($request, $code);

        return view('site.receipt', ['receipt' => $this->data($order, public: false), 'public' => false]);
    }

    public function pdf(Request $request, string $code): Response
    {
        $order = $this->ownedOrder($request, $code);

        return Pdf::loadView('pdf.receipt', ['receipt' => $this->data($order, public: false)])
            ->setPaper('a4')
            // Embute só os caracteres usados das fontes: o PDF cai de ~1,3 MB para algumas dezenas de KB.
            ->setOption('isFontSubsettingEnabled', true)
            ->download("comprovante-{$order->public_code}.pdf");
    }

    private function ownedOrder(Request $request, string $code): Order
    {
        $order = Order::where('public_code', $code)->with('promotion', 'items', 'payments')->firstOrFail();
        abort_unless($order->isAccessibleBy($request->user(), $request->session()) && $order->receipt_code, 404);

        return $order;
    }

    /**
     * Tudo o que o comprovante mostra, num só lugar (página e PDF usam os mesmos dados).
     *
     * @return array<string, mixed>
     */
    private function data(Order $order, bool $public): array
    {
        $promotion = $order->promotion;
        $verifyUrl = route('receipts.verify', $order->receipt_code);
        $payment = $order->payments->sortByDesc('id')->firstWhere('paid_at', '!=', null);

        [$valid, $statusText] = match ($order->status) {
            OrderStatus::Confirmed => [true, 'Válido: pagamento confirmado.'],
            OrderStatus::Refunded => [false, 'Não é mais válido: o pedido foi reembolsado.'],
            OrderStatus::NeedsReview => [false, 'Em verificação: há uma pendência no pagamento deste pedido.'],
            default => [false, 'Não é válido: '.mb_strtolower($order->status->label()).'.'],
        };

        return [
            'platform' => config('app.name'),
            'organizer' => Setting::read('organizer_name'),
            'organizer_document' => Setting::read('organizer_document'),
            'promotion' => $promotion->title,
            'promotion_url' => route('promotions.show', $promotion->slug),
            'buyer' => $public ? $order->maskedBuyerName() : $order->buyer_name,
            'order_code' => $order->public_code,
            'numbers' => $order->items->sortBy('number')->map(fn ($i) => $promotion->formatNumber($i->number))->values()->all(),
            'quantity' => $order->quantity,
            'unit_price' => $order->unit_price_cents,
            'total' => $order->total_cents,
            'method' => $payment?->method->label() ?? $order->payment_method?->label(),
            'payment_status' => $payment?->status->label(),
            'confirmed_at' => $order->confirmed_at,
            'receipt_code' => $order->receipt_code,
            'verify_url' => $verifyUrl,
            'qr' => Qr::svgDataUri($verifyUrl),
            'valid' => $valid,
            'status_text' => $statusText,
            'pdf_url' => $public ? null : route('orders.receipt.pdf', $order->public_code),
            'order_url' => $public ? null : route('orders.show', $order->public_code),
        ];
    }
}
