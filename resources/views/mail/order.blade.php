<x-mail::message>
@if ($kind === 'reserved')
# Seus números estão reservados

Olá, {{ $order->buyer_name }}. Reservamos seus números na promoção **{{ $order->promotion->title }}**.

**Pague até {{ $order->reserved_until->format('d/m/Y \à\s H:i') }} (horário de Brasília).** Depois disso, os números voltam a ficar disponíveis para outras pessoas.
@elseif ($kind === 'confirmed')
# Pagamento confirmado

Olá, {{ $order->buyer_name }}. Seu pagamento na promoção **{{ $order->promotion->title }}** foi confirmado e seus números estão garantidos.
@else
# Link do seu pedido

Recebemos um pedido para acessar o pedido **{{ $order->public_code }}**. Use o botão abaixo.

Se não foi você, ignore este e-mail: ninguém vê o pedido sem este link.
@endif

<x-mail::panel>
Pedido: **{{ $order->public_code }}**<br>
Números: **{{ $numbers }}**<br>
Total: **{{ $total }}**
</x-mail::panel>

<x-mail::button :url="$orderUrl">
Ver meu pedido
</x-mail::button>

@if ($kind === 'confirmed' && $receiptUrl)
Qualquer pessoa pode conferir a autenticidade do seu comprovante em: {{ $receiptUrl }}
@endif

Este link é pessoal: não compartilhe com outras pessoas.

{{ config('app.name') }}
</x-mail::message>
