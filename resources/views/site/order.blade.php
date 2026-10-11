@php
    use App\Enums\OrderStatus;
    use App\Enums\PaymentMethod;
    use App\Support\Format;

    $promotion = $order->promotion;
    $payable = $order->isPayable();
    $identified = $order->isIdentified();
    $numbers = $order->items->sortBy('number')->pluck('number');
    $user = auth()->user();
    $methods = collect($promotion->payment_methods)->map(fn ($m) => PaymentMethod::from($m));

    $statusClasses = match ($order->status) {
        OrderStatus::Confirmed => 'bg-ok-claro text-ok',
        OrderStatus::AwaitingPayment, OrderStatus::Processing => 'bg-aviso-claro text-aviso',
        OrderStatus::NeedsReview => 'bg-talao-claro text-tinta',
        default => 'bg-zinc-100 text-tinta-suave',
    };

    $steps = ['Números reservados', 'Seus dados', 'Pagamento', 'Comprovante'];
    $current = match (true) {
        $order->status === OrderStatus::Confirmed => 4,
        $order->status === OrderStatus::Processing, $payable && $identified => 3,
        default => 2,
    };

    // Cobrança em aberto (ou recusada) da forma de pagamento escolhida.
    $payment = $order->payments()->where('method', $order->payment_method)
        ->whereIn('status', [\App\Enums\PaymentStatus::Pending, \App\Enums\PaymentStatus::Failed])->latest('id')->first();
    $watching = in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::Processing], true);
    $sandbox = config('services.asaas.env') !== 'production';
@endphp

<x-layouts::public :title="'Pedido '.$order->public_code">
    <div class="mx-auto max-w-4xl px-4 pt-8 sm:px-6">
        <p class="text-sm text-tinta-suave"><a href="{{ route('promotions.show', $promotion->slug) }}" class="hover:text-tinta hover:underline">{{ $promotion->title }}</a></p>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="font-display text-3xl tracking-tight">Pedido <span class="num">{{ $order->public_code }}</span></h1>
            <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $statusClasses }}">{{ $order->status->label() }}</span>
        </div>

        @if (session('status'))
            <p role="status" class="mt-4 rounded-[10px] border border-ok/30 bg-ok-claro px-4 py-3 text-sm text-ok">{{ session('status') }}</p>
        @endif
        @error('order')
            <p role="alert" class="mt-4 rounded-[10px] border border-carimbo/30 bg-carimbo-claro px-4 py-3 text-sm text-carimbo">{{ $message }}</p>
        @enderror
        @if ($promotion->status === \App\Enums\PromotionStatus::Cancelled)
            <p class="mt-4 rounded-[10px] border border-carimbo/30 bg-carimbo-claro px-4 py-3 text-sm text-carimbo">
                Esta promoção foi cancelada pelo organizador.
                @if ($order->status === OrderStatus::Confirmed) O valor pago será devolvido; em caso de dúvida, fale com o suporte informando o código do pedido. @endif
            </p>
        @endif

        @if (in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::Processing, OrderStatus::Confirmed], true))
            <ol class="mt-6 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4" aria-label="Etapas do pedido">
                @foreach ($steps as $i => $label)
                    @php($n = $i + 1)
                    <li @class([
                        'rounded-[10px] border px-3 py-2',
                        'border-tinta bg-talao-claro font-semibold' => $n === $current,
                        'text-tinta-suave' => $n !== $current,
                    ]) @if ($n === $current) aria-current="step" @endif>
                        <span class="num text-xs">{{ $n }}.</span> {{ $label }}
                        @if ($n < $current) <span class="sr-only">(concluída)</span><span aria-hidden="true"> ✓</span> @endif
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Prazo da reserva. Ao zerar, a página recarrega e mostra o pedido expirado (quem decide é o servidor). --}}
        @if ($payable)
            <div x-data="countdown(@js($order->reserved_until->toIso8601String()))" class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-(--radius-bilhete) border-2 border-tinta bg-cartao px-5 py-4">
                <p>Seus números estão reservados por mais <strong class="num text-xl" x-text="label">{{ $order->reserved_until->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</strong>.</p>
                <p class="text-sm text-tinta-suave">Até {{ $order->reserved_until->format('H:i') }} (horário de Brasília). Depois disso, os números voltam a ficar disponíveis.</p>
            </div>
        @elseif ($order->status === OrderStatus::Expired)
            <div class="mt-6 rounded-(--radius-bilhete) border bg-cartao px-5 py-4">
                <p class="font-semibold">O prazo da reserva acabou e os números foram liberados.</p>
                <p class="mt-1 text-sm text-tinta-suave">Se ainda quiser participar, escolha seus números de novo. Eles podem já ter sido reservados por outra pessoa.</p>
                <a href="{{ route('numbers.show', $promotion->slug) }}" class="btn btn-primary mt-4">Escolher números de novo</a>
            </div>
        @elseif ($order->status === OrderStatus::Cancelled)
            <div class="mt-6 rounded-(--radius-bilhete) border bg-cartao px-5 py-4">
                <p class="font-semibold">Pedido cancelado. Os números foram liberados.</p>
                <a href="{{ route('promotions.show', $promotion->slug) }}" class="btn btn-secondary mt-4">Voltar para a promoção</a>
            </div>
        @endif

        {{-- Resumo do pedido. --}}
        <section class="bilhete mt-6" aria-labelledby="titulo-resumo">
            <div class="p-6">
                <h2 id="titulo-resumo" class="font-bold">Resumo</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-tinta-suave">Promoção</dt><dd class="font-semibold">{{ $promotion->title }}</dd></div>
                    <div><dt class="text-tinta-suave">Quantidade</dt><dd class="num font-semibold">{{ $order->quantity }}</dd></div>
                    <div><dt class="text-tinta-suave">Valor por número</dt><dd class="num font-semibold">{{ Format::brl($order->unit_price_cents) }}</dd></div>
                    @if ($order->payment_method)
                        <div><dt class="text-tinta-suave">Forma de pagamento</dt><dd class="font-semibold">{{ $order->payment_method->label() }}</dd></div>
                    @endif
                </dl>
                <h3 class="mt-5 text-sm text-tinta-suave">Números</h3>
                <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="Números do pedido">
                    @foreach ($numbers as $n)
                        <li @class([
                            'cel num px-2.5 py-1 text-sm',
                            'cel-minha' => in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::Processing, OrderStatus::Confirmed], true),
                            'text-tinta-suave line-through' => ! in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::Processing, OrderStatus::Confirmed], true),
                        ])>{{ $promotion->formatNumber($n) }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="bilhete-canhoto flex items-center justify-between gap-4 px-6 py-4">
                <p class="text-sm text-tinta-suave">Total</p>
                <p class="num text-2xl font-semibold">{{ Format::brl($order->total_cents) }}</p>
            </div>
        </section>

        {{-- Identificação: aparece enquanto a reserva vale e os dados ainda não foram confirmados. --}}
        @if ($payable && ! $identified)
            <section class="mt-8" aria-labelledby="titulo-dados">
                <h2 id="titulo-dados" class="font-display text-xl">Seus dados</h2>
                <p class="mt-1 text-sm text-tinta-suave">Usados para gerar a cobrança e emitir o comprovante. O CPF não aparece na consulta pública do comprovante.</p>

                <form method="POST" action="{{ route('orders.identify', $order->public_code) }}" class="mt-5 grid gap-5 rounded-(--radius-bilhete) border bg-cartao p-6 sm:grid-cols-2">
                    @csrf
                    @method('PUT')

                    @php($field = 'min-h-11 w-full rounded-[10px] border bg-papel px-3 text-base focus:bg-cartao')

                    <label class="grid gap-1 text-sm font-medium sm:col-span-2">
                        Nome completo
                        <input name="buyer_name" required autocomplete="name" value="{{ old('buyer_name', $order->buyer_name ?? $user?->name) }}" class="{{ $field }}" aria-describedby="erro-nome" @error('buyer_name') aria-invalid="true" @enderror>
                        @error('buyer_name') <span id="erro-nome" class="text-carimbo">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        E-mail
                        <input name="buyer_email" type="email" required autocomplete="email" value="{{ old('buyer_email', $order->buyer_email ?? $user?->email) }}" class="{{ $field }}" aria-describedby="erro-email" @error('buyer_email') aria-invalid="true" @enderror>
                        @error('buyer_email') <span id="erro-email" class="text-carimbo">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        CPF
                        <input name="buyer_cpf" required inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" value="{{ old('buyer_cpf') }}" class="num {{ $field }}" aria-describedby="erro-cpf" @error('buyer_cpf') aria-invalid="true" @enderror>
                        @error('buyer_cpf') <span id="erro-cpf" class="text-carimbo">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        Telefone (opcional)
                        <input name="buyer_phone" type="tel" autocomplete="tel" value="{{ old('buyer_phone', $order->buyer_phone ?? $user?->phone) }}" class="{{ $field }}">
                    </label>

                    <fieldset class="sm:col-span-2" aria-describedby="erro-pagamento">
                        <legend class="text-sm font-medium">Forma de pagamento</legend>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($methods as $method)
                                <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border px-4 has-[:checked]:border-tinta has-[:checked]:bg-talao-claro">
                                    <input type="radio" name="payment_method" value="{{ $method->value }}" required @checked(old('payment_method', $methods->count() === 1 ? $method->value : null) === $method->value)>
                                    {{ $method->label() }}
                                </label>
                            @endforeach
                        </div>
                        @error('payment_method') <p id="erro-pagamento" class="mt-1 text-sm text-carimbo">{{ $message }}</p> @enderror
                    </fieldset>

                    <label class="flex items-start gap-3 text-sm sm:col-span-2">
                        <input type="checkbox" name="accept_terms" value="1" required class="mt-1 size-4" aria-describedby="erro-termos" @error('accept_terms') aria-invalid="true" @enderror @checked(old('accept_terms'))>
                        <span>Li e aceito as <a href="{{ route('promotions.show', $promotion->slug) }}#titulo-regras" target="_blank" class="underline">regras da promoção</a> e os <a href="{{ route('pages.show', 'termos-de-uso') }}" target="_blank" class="underline">termos de uso</a>.</span>
                    </label>
                    @error('accept_terms') <p id="erro-termos" class="-mt-3 text-sm text-carimbo sm:col-span-2">{{ $message }}</p> @enderror

                    <div class="sm:col-span-2">
                        <button type="submit" class="btn btn-primary">Confirmar dados</button>
                    </div>
                </form>
            </section>
        @endif

        {{-- Pagamento. A confirmação vem só do provedor; esta página apenas consulta a situação. --}}
        @if (($payable && $identified) || $order->status === OrderStatus::Processing)
            <section class="mt-8 rounded-(--radius-bilhete) border bg-cartao p-6" aria-labelledby="titulo-pagamento">
                <h2 id="titulo-pagamento" class="font-display text-xl">Pagamento</h2>
                <p class="mt-2 text-sm">Comprador: <strong>{{ $order->buyer_name }}</strong> · {{ $order->buyer_email }}</p>

                @if ($sandbox)
                    <p class="mt-3 text-xs font-semibold text-aviso">Ambiente de testes: nenhum dinheiro real é cobrado.</p>
                @endif

                @if ($order->status === OrderStatus::Processing)
                    <p class="mt-4 rounded-[10px] border border-aviso/30 bg-aviso-claro px-4 py-3 text-sm text-aviso" role="status">
                        Seu pagamento está em análise pelo sistema de pagamento. Seus números continuam reservados; esta página atualiza sozinha.
                    </p>
                @elseif (! $payment && $promotion->phase() !== \App\Enums\PromotionPhase::Open)
                    <p class="mt-4 rounded-[10px] border border-aviso/30 bg-aviso-claro px-4 py-3 text-sm text-aviso" role="status">
                        Esta promoção está {{ mb_strtolower($promotion->phase()->label()) }} e não aceita novos pagamentos no momento.
                        @if ($promotion->phase() === \App\Enums\PromotionPhase::Paused)
                            Seus números continuam reservados até {{ $order->reserved_until->format('H:i') }} (horário de Brasília); se a promoção for retomada antes disso, você poderá pagar por aqui.
                        @endif
                    </p>
                @elseif (! $payment)
                    <form method="POST" action="{{ route('orders.pay', $order->public_code) }}" class="mt-5" x-data="{ sending: false }" @submit="sending = true">
                        @csrf
                        @if ($order->payment_method === PaymentMethod::CreditCard)
                            <p class="text-sm text-tinta-suave">Você vai preencher os dados do cartão no ambiente seguro do provedor de pagamento. Eles não passam por este site.</p>
                        @endif
                        <button type="submit" class="btn btn-primary mt-3" :disabled="sending" :aria-busy="sending ? 'true' : 'false'">
                            {{ $order->payment_method === PaymentMethod::Pix ? 'Gerar Pix de '.Format::brl($order->total_cents) : 'Pagar '.Format::brl($order->total_cents).' com cartão' }}
                        </button>
                    </form>
                @elseif ($payment->status === \App\Enums\PaymentStatus::Failed)
                    <p class="mt-4 rounded-[10px] border border-carimbo/30 bg-carimbo-claro px-4 py-3 text-sm text-carimbo" role="alert">
                        O pagamento não foi aprovado. {{ $payment->failure_reason }} Você pode tentar de novo enquanto a reserva estiver valendo.
                    </p>
                    @if ($payment->invoice_url)
                        <a href="{{ $payment->invoice_url }}" class="btn btn-primary mt-4" rel="noopener">Tentar pagar de novo</a>
                    @endif
                @elseif ($payment->method === PaymentMethod::Pix && $payment->pix_payload)
                    <div class="mt-5 grid items-start gap-6 sm:grid-cols-[auto_1fr]">
                        @if ($payment->pix_qr_base64)
                            <img src="data:image/png;base64,{{ $payment->pix_qr_base64 }}" alt="QR Code do Pix de {{ Format::brl($payment->amount_cents) }}" class="size-56 rounded-[10px] border bg-white p-2">
                        @endif
                        <div class="space-y-4" x-data="{ copied: false }">
                            <p class="text-sm">Valor: <strong class="num text-lg">{{ Format::brl($payment->amount_cents) }}</strong></p>
                            <ol class="list-decimal space-y-1 pl-5 text-sm text-tinta-suave">
                                <li>Abra o app do seu banco e escolha pagar com Pix.</li>
                                <li>Leia o QR Code ou use o código "copia e cola".</li>
                                <li>Pague até {{ $order->reserved_until->format('H:i') }} (horário de Brasília), enquanto a reserva vale.</li>
                            </ol>
                            <label class="grid gap-1 text-sm font-medium">
                                Pix copia e cola
                                <textarea readonly rows="3" class="num w-full rounded-[10px] border bg-papel p-3 text-xs break-all" @focus="$el.select()">{{ $payment->pix_payload }}</textarea>
                            </label>
                            <button type="button" class="btn btn-secondary"
                                @click="navigator.clipboard.writeText(@js($payment->pix_payload)).then(() => { copied = true; setTimeout(() => copied = false, 3000) })">
                                <span x-text="copied ? 'Código copiado' : 'Copiar código'">Copiar código</span>
                            </button>
                            <p class="sr-only" aria-live="polite" x-text="copied ? 'Código Pix copiado' : ''"></p>
                        </div>
                    </div>
                    <p class="mt-5 text-sm text-tinta-suave" role="status">Assim que o pagamento for confirmado pelo banco, esta página atualiza sozinha.</p>
                @elseif ($payment->invoice_url)
                    <p class="mt-4 text-sm">A cobrança foi gerada. Conclua o pagamento na página segura do provedor:</p>
                    <a href="{{ $payment->invoice_url }}" class="btn btn-primary mt-3" rel="noopener">
                        {{ $payment->method === PaymentMethod::CreditCard ? 'Continuar pagamento com cartão' : 'Abrir página de pagamento' }}
                    </a>
                    <p class="mt-4 text-sm text-tinta-suave" role="status">Depois de pagar, volte para esta página: a confirmação aparece aqui automaticamente.</p>
                @endif
            </section>
        @endif

        @if ($order->status === OrderStatus::Confirmed)
            <section class="mt-8 rounded-(--radius-bilhete) border-2 border-ok bg-ok-claro p-6" role="status">
                <h2 class="font-display text-xl text-ok">Pagamento confirmado</h2>
                <p class="mt-2">Confirmado em {{ $order->confirmed_at->format('d/m/Y \à\s H:i') }} (horário de Brasília). Seus números estão garantidos.</p>
                @if ($order->receipt_code)
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('orders.receipt', $order->public_code) }}" class="btn btn-primary">Ver comprovante</a>
                        <a href="{{ route('orders.receipt.pdf', $order->public_code) }}" class="btn btn-secondary">Baixar PDF</a>
                    </div>
                @endif
            </section>
        @elseif ($order->status === OrderStatus::NeedsReview)
            <section class="mt-8 rounded-(--radius-bilhete) border bg-talao-claro p-6" role="status">
                <h2 class="font-display text-xl">Pedido em verificação</h2>
                <p class="mt-2">Recebemos uma atualização de pagamento que precisa de verificação manual. Vamos entrar em contato pelo e-mail {{ $order->buyer_email }}. Se preferir, fale com o suporte informando o código do pedido.</p>
            </section>
        @elseif ($order->status === OrderStatus::Refunded)
            <section class="mt-8 rounded-(--radius-bilhete) border bg-cartao p-6" role="status">
                <h2 class="font-display text-xl">Pedido reembolsado</h2>
                <p class="mt-2">O valor foi devolvido{{ $order->refunded_at ? ' em '.$order->refunded_at->format('d/m/Y') : '' }}. O prazo para aparecer na sua conta depende do banco ou da operadora do cartão.</p>
            </section>
        @endif

        @if ($order->status === OrderStatus::AwaitingPayment)
            <details class="mt-8 text-sm">
                <summary class="cursor-pointer text-tinta-suave underline">Desistir deste pedido</summary>
                <form method="POST" action="{{ route('orders.cancel', $order->public_code) }}" class="mt-3 rounded-[10px] border bg-cartao p-4">
                    @csrf
                    <p>Os números {{ $numbers->map(fn ($n) => $promotion->formatNumber($n))->join(', ', ' e ') }} serão liberados para outras pessoas.</p>
                    <button type="submit" class="btn btn-secondary mt-3">Cancelar pedido e liberar números</button>
                </form>
            </details>
        @endif

        <p class="mt-8 text-xs text-tinta-suave">Guarde o código <span class="num font-semibold text-tinta">{{ $order->public_code }}</span>. Ele identifica o seu pedido no suporte.</p>
    </div>

    @if ($watching)
        @push('scripts')
            @livewireScripts
            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('countdown', (deadline) => ({
                        label: '',
                        init() {
                            let end = new Date(deadline).getTime()
                            let tick = () => {
                                let s = Math.max(0, Math.round((end - Date.now()) / 1000))
                                this.label = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0')
                                if (s === 0) { clearInterval(timer); setTimeout(() => window.location.reload(), 1500) }
                            }
                            let timer = setInterval(tick, 1000)
                            tick()
                        },
                    }))
                })

                // Consulta a situação a cada 5 s e recarrega quando o pedido ou o pagamento mudam.
                ;(() => {
                    let url = @js(route('orders.status', $order->public_code));
                    let known = @js(['order' => $order->status->value, 'payment' => $order->payments()->latest('id')->value('status')?->value]);
                    let poll = async () => {
                        try {
                            let r = await fetch(url, { headers: { Accept: 'application/json' } })
                            if (r.ok) {
                                let now = await r.json()
                                if (now.order !== known.order || now.payment !== known.payment) return window.location.reload()
                            }
                        } catch (e) {}
                        setTimeout(poll, 5000)
                    }
                    setTimeout(poll, 5000)
                })()
            </script>
        @endpush
    @endif

</x-layouts::public>
