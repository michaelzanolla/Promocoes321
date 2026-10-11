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
        $payable && $identified => 3,
        default => 2,
    };
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
                        <input name="buyer_name" required autocomplete="name" value="{{ old('buyer_name', $order->buyer_name ?? $user?->name) }}" class="{{ $field }}" aria-describedby="erro-nome">
                        @error('buyer_name') <span id="erro-nome" class="text-carimbo">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        E-mail
                        <input name="buyer_email" type="email" required autocomplete="email" value="{{ old('buyer_email', $order->buyer_email ?? $user?->email) }}" class="{{ $field }}" aria-describedby="erro-email">
                        @error('buyer_email') <span id="erro-email" class="text-carimbo">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        CPF
                        <input name="buyer_cpf" required inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" value="{{ old('buyer_cpf') }}" class="num {{ $field }}" aria-describedby="erro-cpf">
                        @error('buyer_cpf') <span id="erro-cpf" class="text-carimbo">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-1 text-sm font-medium">
                        Telefone (opcional)
                        <input name="buyer_phone" type="tel" autocomplete="tel" value="{{ old('buyer_phone', $order->buyer_phone ?? $user?->phone) }}" class="{{ $field }}">
                    </label>

                    <fieldset class="sm:col-span-2">
                        <legend class="text-sm font-medium">Forma de pagamento</legend>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($methods as $method)
                                <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border px-4 has-[:checked]:border-tinta has-[:checked]:bg-talao-claro">
                                    <input type="radio" name="payment_method" value="{{ $method->value }}" required @checked(old('payment_method', $methods->count() === 1 ? $method->value : null) === $method->value)>
                                    {{ $method->label() }}
                                </label>
                            @endforeach
                        </div>
                        @error('payment_method') <p class="mt-1 text-sm text-carimbo">{{ $message }}</p> @enderror
                    </fieldset>

                    <label class="flex items-start gap-3 text-sm sm:col-span-2">
                        <input type="checkbox" name="accept_terms" value="1" required class="mt-1 size-4" @checked(old('accept_terms'))>
                        <span>Li e aceito as <a href="{{ route('promotions.show', $promotion->slug) }}#titulo-regras" target="_blank" class="underline">regras da promoção</a> e os <a href="{{ route('pages.show', 'termos-de-uso') }}" target="_blank" class="underline">termos de uso</a>.</span>
                    </label>
                    @error('accept_terms') <p class="-mt-3 text-sm text-carimbo sm:col-span-2">{{ $message }}</p> @enderror

                    <div class="sm:col-span-2">
                        <button type="submit" class="btn btn-primary">Confirmar dados</button>
                    </div>
                </form>
            </section>
        @endif

        {{-- Pagamento: a cobrança real é gerada pela integração com o Asaas (Fase 6). --}}
        @if ($payable && $identified)
            <section class="mt-8 rounded-(--radius-bilhete) border bg-cartao p-6" aria-labelledby="titulo-pagamento">
                <h2 id="titulo-pagamento" class="font-display text-xl">Pagamento</h2>
                <p class="mt-2 text-sm">Comprador: <strong>{{ $order->buyer_name }}</strong> · {{ $order->buyer_email }}</p>
                <p class="mt-4 rounded-[10px] border border-aviso/30 bg-aviso-claro px-4 py-3 text-sm text-aviso">
                    A cobrança por {{ $order->payment_method->label() }} ainda não está disponível neste ambiente.
                    A integração com o provedor de pagamento está em desenvolvimento.
                </p>
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

    @if ($payable)
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
            </script>
        @endpush
    @endif
</x-layouts::public>
