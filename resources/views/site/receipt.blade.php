@php
    use App\Support\Format;
    $r = $receipt;
@endphp

<x-layouts::public :title="'Comprovante '.$r['receipt_code']">
    <div class="mx-auto max-w-3xl px-4 pt-8 sm:px-6 print:max-w-none print:p-0">
        @if ($public)
            <p class="text-sm text-tinta-suave print:hidden">Verificação pública de comprovante. Dados pessoais do comprador não são exibidos.</p>
        @else
            <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
                <a href="{{ $r['order_url'] }}" class="text-sm text-tinta-suave hover:text-tinta hover:underline">Voltar para o pedido</a>
                <div class="flex gap-2">
                    <button type="button" onclick="window.print()" class="btn btn-secondary">Imprimir</button>
                    <a href="{{ $r['pdf_url'] }}" class="btn btn-primary">Baixar PDF</a>
                </div>
            </div>
        @endif

        <p role="status" @class([
            'mt-5 rounded-[10px] border px-4 py-3 font-semibold',
            'border-ok/30 bg-ok-claro text-ok' => $r['valid'],
            'border-carimbo/30 bg-carimbo-claro text-carimbo' => ! $r['valid'],
        ])>{{ $r['status_text'] }}</p>

        <article class="bilhete bilhete-horizontal mt-5 shadow-[0_24px_48px_-28px_rgb(18_52_59/0.35)] print:shadow-none" aria-labelledby="titulo-comprovante">
            <div class="p-6 sm:p-8">
                <p class="num text-[0.7rem] tracking-[0.3em] text-tinta-suave uppercase">{{ $r['platform'] }} · Comprovante</p>
                <h1 id="titulo-comprovante" class="mt-3 font-display text-2xl leading-tight tracking-tight">{{ $r['promotion'] }}</h1>
                @if ($r['organizer'])
                    <p class="mt-1 text-sm text-tinta-suave">Responsável: {{ $r['organizer'] }}@if ($r['organizer_document']) · <span class="num">{{ $r['organizer_document'] }}</span>@endif</p>
                @endif

                <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="text-tinta-suave">Comprador</dt><dd class="font-semibold">{{ $r['buyer'] }}</dd></div>
                    <div><dt class="text-tinta-suave">Pedido</dt><dd class="num font-semibold">{{ $r['order_code'] }}</dd></div>
                    <div><dt class="text-tinta-suave">Quantidade</dt><dd class="num font-semibold">{{ $r['quantity'] }}</dd></div>
                    <div><dt class="text-tinta-suave">Valor por número</dt><dd class="num font-semibold">{{ Format::brl($r['unit_price']) }}</dd></div>
                    <div><dt class="text-tinta-suave">Total</dt><dd class="num text-lg font-semibold">{{ Format::brl($r['total']) }}</dd></div>
                    <div><dt class="text-tinta-suave">Pagamento</dt><dd class="font-semibold">{{ $r['method'] }}@if ($r['payment_status']) · {{ $r['payment_status'] }}@endif</dd></div>
                    @if ($r['confirmed_at'])
                        <div class="col-span-2"><dt class="text-tinta-suave">Confirmado em</dt><dd class="font-semibold">{{ $r['confirmed_at']->format('d/m/Y \à\s H:i') }} (horário de Brasília)</dd></div>
                    @endif
                </dl>

                <h2 class="mt-6 text-sm text-tinta-suave">Números confirmados</h2>
                <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="Números confirmados">
                    @foreach ($r['numbers'] as $n)
                        <li @class(['cel num px-2.5 py-1 text-sm', 'cel-minha' => $r['valid'], 'text-tinta-suave line-through' => ! $r['valid']])>{{ $n }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="bilhete-canhoto flex flex-col items-center justify-center gap-2 px-6 py-6 text-center sm:w-52">
                <img src="{{ $r['qr'] }}" alt="QR Code para verificar este comprovante" class="size-36">
                <p class="text-xs text-tinta-suave">Código de verificação</p>
                <p class="num text-sm font-semibold break-all">{{ $r['receipt_code'] }}</p>
            </div>
        </article>

        <p class="mt-5 text-sm text-tinta-suave">
            Para conferir a autenticidade, leia o QR Code ou acesse
            <a href="{{ $r['verify_url'] }}" class="num break-all underline">{{ $r['verify_url'] }}</a>.
            A página de verificação mostra sempre a situação atual registrada no sistema.
        </p>
    </div>
</x-layouts::public>
