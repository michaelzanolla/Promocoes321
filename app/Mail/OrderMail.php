<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\Format;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mails do pedido. Um só Mailable com três modelos: reservado, confirmado e link solicitado.
 */
class OrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public const RESERVED = 'reserved';

    public const CONFIRMED = 'confirmed';

    public const LINK = 'link';

    public function __construct(public Order $order, public string $kind) {}

    public function envelope(): Envelope
    {
        $code = $this->order->public_code;

        return new Envelope(subject: match ($this->kind) {
            self::RESERVED => "Pedido {$code}: seus números estão reservados",
            self::CONFIRMED => "Pagamento confirmado: pedido {$code}",
            default => "Link do seu pedido {$code}",
        });
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing('promotion', 'items');

        return new Content(markdown: 'mail.order', with: [
            'kind' => $this->kind,
            'order' => $order,
            'numbers' => $order->items->sortBy('number')->map(fn ($i) => $order->promotion->formatNumber($i->number))->join(', '),
            'total' => Format::brl($order->total_cents),
            'orderUrl' => $order->secretUrl(),
            'receiptUrl' => $order->receipt_code ? route('receipts.verify', $order->receipt_code) : null,
        ]);
    }
}
