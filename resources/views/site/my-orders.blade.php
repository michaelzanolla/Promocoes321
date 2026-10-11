@php
    use App\Enums\OrderStatus;
    use App\Support\Format;

    $groups = [
        'Aguardando pagamento' => [OrderStatus::AwaitingPayment, OrderStatus::Processing],
        'Confirmados' => [OrderStatus::Confirmed],
        'Em verificação' => [OrderStatus::NeedsReview],
        'Expirados, cancelados ou reembolsados' => [OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Refunded],
    ];
@endphp

<x-layouts::public title="Minhas compras">
    <div class="mx-auto max-w-4xl px-4 pt-10 sm:px-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl tracking-tight">Minhas compras</h1>
                <p class="mt-1 text-tinta-suave">Olá, {{ auth()->user()->name }}.</p>
            </div>
            <nav aria-label="Minha conta" class="flex flex-wrap gap-2 text-sm">
                <a href="{{ route('profile.edit') }}" class="btn btn-secondary">Meus dados</a>
                <a href="{{ route('pages.show', 'contato') }}" class="btn btn-secondary">Suporte</a>
            </nav>
        </div>

        @if ($orders->isEmpty())
            <div class="mt-8 rounded-(--radius-bilhete) border border-dashed bg-cartao px-6 py-12 text-center">
                <p class="font-semibold">Você ainda não tem compras.</p>
                <a href="{{ route('home') }}#promocoes" class="btn btn-primary mt-5">Ver promoções</a>
            </div>
        @endif

        @foreach ($groups as $title => $statuses)
            @php($list = $orders->filter(fn ($o) => in_array($o->status, $statuses, true)))
            @continue($list->isEmpty())

            <section class="mt-10" aria-labelledby="g-{{ $loop->index }}">
                <h2 id="g-{{ $loop->index }}" class="font-display text-xl">{{ $title }}</h2>
                <ul class="mt-4 grid gap-3">
                    @foreach ($list as $order)
                        <li class="bilhete bilhete-horizontal">
                            <div class="p-5">
                                <p class="text-sm text-tinta-suave">{{ $order->promotion->title }}</p>
                                <p class="mt-1 font-semibold">Pedido <span class="num">{{ $order->public_code }}</span> · {{ $order->status->label() }}</p>
                                <p class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($order->items->sortBy('number') as $item)
                                        <span @class(['num rounded border px-1.5 text-xs', 'text-tinta-suave line-through' => in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Refunded], true)])>{{ $order->promotion->formatNumber($item->number) }}</span>
                                    @endforeach
                                </p>
                                <p class="mt-2 text-xs text-tinta-suave">{{ $order->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                            <div class="bilhete-canhoto flex flex-col items-start justify-center gap-2 px-5 py-4 sm:w-48 sm:items-center">
                                <span class="num font-semibold">{{ Format::brl($order->total_cents) }}</span>
                                <a href="{{ route('orders.show', $order->public_code) }}" class="btn btn-secondary">Ver pedido</a>
                                @if ($order->receipt_code)
                                    <a href="{{ route('orders.receipt', $order->public_code) }}" class="text-sm underline">Comprovante</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-layouts::public>
