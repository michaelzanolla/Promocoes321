@php
    use App\Support\Format;

    $config = [
        'start' => $promotion->number_start,
        'end' => $promotion->lastNumber(),
        'digits' => strlen((string) $promotion->lastNumber()),
        'price' => $promotion->unit_price_cents,
        'min' => $promotion->min_per_order,
        'max' => min($promotion->max_per_order ?? \App\Services\ReserveNumbers::HARD_MAX_PER_ORDER, \App\Services\ReserveNumbers::HARD_MAX_PER_ORDER),
        'allowRandom' => $promotion->allow_random,
        'statusUrl' => route('numbers.status', $promotion->slug),
        'reserveUrl' => route('numbers.reserve', $promotion->slug),
        'storageKey' => 'selecao-'.$promotion->id,
    ];
@endphp

<x-layouts::public :title="'Escolha seus números · '.$promotion->title">
    <div x-data="numberPicker(@js($config))" class="mx-auto max-w-6xl px-4 pt-8 pb-40 sm:px-6">
        <nav aria-label="Você está em" class="text-sm text-tinta-suave">
            <a href="{{ route('promotions.show', $promotion->slug) }}" class="hover:text-tinta hover:underline">{{ $promotion->title }}</a>
            <span aria-hidden="true"> / </span>
            <span aria-current="page">Escolha seus números</span>
        </nav>

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl tracking-tight">Escolha seus números</h1>
                <p class="mt-1 text-tinta-suave">
                    <span class="num">{{ Format::brl($promotion->unit_price_cents) }}</span> por número.
                    @if ($promotion->max_per_order)
                        De {{ $promotion->min_per_order }} a {{ $promotion->max_per_order }} números por pedido.
                    @endif
                </p>
            </div>
            <p class="text-xs text-tinta-suave" aria-live="polite">
                <template x-if="offline"><span class="font-semibold text-carimbo">Sem conexão. A grade pode estar desatualizada.</span></template>
                <template x-if="!offline && updatedAt"><span>Atualizado às <span class="num" x-text="updatedAt"></span>. A confirmação final acontece ao reservar.</span></template>
            </p>
        </div>

        {{-- Ferramentas: busca, filtro e aleatórios. --}}
        <div class="mt-6 grid gap-3 rounded-(--radius-bilhete) border bg-cartao p-4 md:grid-cols-[1fr_auto_auto]">
            <form class="grid gap-1 text-sm font-medium" @submit.prevent="find()">
                <label for="busca-numero">Procurar um número</label>
                <div class="flex gap-2">
                    <input id="busca-numero" x-model="search" inputmode="numeric" autocomplete="off" placeholder="Ex.: {{ $promotion->formatNumber($promotion->number_start + 41) }}"
                        class="num min-h-11 w-full rounded-[10px] border bg-papel px-3 text-base focus:bg-cartao">
                    <button type="submit" class="btn btn-secondary">Ir</button>
                </div>
            </form>

            <div class="grid gap-1 text-sm font-medium">
                <label for="filtro">Mostrar</label>
                <select id="filtro" x-model="filter" @change="page = 0" class="min-h-11 rounded-[10px] border bg-papel px-3 text-base">
                    <option value="all">Todos os números</option>
                    <option value="available">Só disponíveis</option>
                    <option value="selected">Só os que escolhi</option>
                </select>
            </div>

            @if ($promotion->allow_random)
                <form class="grid gap-1 text-sm font-medium" @submit.prevent="random()">
                    <label for="qtd-aleatoria">Números aleatórios</label>
                    <div class="flex gap-2">
                        <input id="qtd-aleatoria" type="number" x-model.number="randomCount" min="1" :max="max"
                            class="num min-h-11 w-20 rounded-[10px] border bg-papel px-3 text-base focus:bg-cartao">
                        <button type="submit" class="btn btn-secondary">Sortear para mim</button>
                    </div>
                </form>
            @endif
        </div>

        {{-- Legenda: cada estado tem forma e texto, não só cor. --}}
        <ul class="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-sm" aria-label="Legenda">
            <li class="flex items-center gap-2"><span class="cel cel-livre size-6" aria-hidden="true"></span> Disponível</li>
            <li class="flex items-center gap-2"><span class="cel cel-minha size-6" aria-hidden="true">✓</span> Escolhido por você</li>
            <li class="flex items-center gap-2"><span class="cel cel-reservado size-6" aria-hidden="true"></span> Reservado (aguardando pagamento)</li>
            <li class="flex items-center gap-2"><span class="cel cel-vendido size-6" aria-hidden="true"></span> Vendido</li>
        </ul>

        <p x-show="message" x-text="message" role="alert" class="mt-5 rounded-[10px] border px-4 py-3 text-sm"
            :class="messageKind === 'erro' ? 'border-carimbo/30 bg-carimbo-claro text-carimbo' : 'border-aviso/30 bg-aviso-claro text-aviso'" x-cloak></p>

        {{-- Páginas de faixas para grades grandes. --}}
        <nav x-show="pageCount > 1" aria-label="Faixas de números" class="mt-6 flex flex-wrap gap-2" x-cloak>
            <template x-for="p in pageCount" :key="p">
                <button type="button" @click="page = p - 1" :aria-current="page === p - 1 ? 'true' : null"
                    class="num rounded-md border px-2.5 py-1.5 text-xs"
                    :class="page === p - 1 ? 'border-tinta bg-tinta text-white' : 'bg-cartao hover:border-tinta'"
                    x-text="rangeLabel(p - 1)"></button>
            </template>
        </nav>

        <div class="mt-4" role="group" aria-label="Números da promoção">
            <p x-show="visible.length === 0" class="rounded-(--radius-bilhete) border border-dashed bg-cartao px-6 py-10 text-center text-tinta-suave" x-cloak
                x-text="filter === 'selected' ? 'Você ainda não escolheu nenhum número.' : 'Nenhum número disponível nesta faixa.'"></p>

            {{-- Uma parada só no Tab; dentro da grade, setas, Home e End. --}}
            <p id="dica-grade" class="sr-only">Use as setas para mover entre os números e Enter ou espaço para escolher.</p>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(3.75rem,1fr))] gap-1.5" aria-describedby="dica-grade"
                x-effect="tabStop = visible.includes(cursor) ? cursor : visible[0]" @keydown="move($event)">
                <template x-for="n in visible" :key="n">
                    <button type="button" @click="toggle(n)" :id="'n' + n" :tabindex="n === tabStop ? 0 : -1" @focus="cursor = n"
                        class="cel num min-h-11 text-sm"
                        :class="'cel-' + state(n)"
                        :aria-pressed="state(n) === 'minha'"
                        :aria-disabled="state(n) === 'vendido' || state(n) === 'reservado'"
                        :aria-label="fmt(n) + ', ' + stateLabel(n)">
                        <span x-text="fmt(n)"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- Barra fixa com a seleção, o subtotal e a ação. --}}
        <div class="fixed inset-x-0 bottom-0 z-30 border-t bg-cartao/95 shadow-[0_-8px_24px_-12px_rgb(18_52_59/0.25)] backdrop-blur">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3 sm:px-6">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold" aria-live="polite">
                        <span x-text="selected.length === 0 ? 'Nenhum número escolhido' : (selected.length + (selected.length === 1 ? ' número' : ' números') + ': ')"></span>
                        <span class="num" x-show="selected.length" x-text="brl(selected.length * price)"></span>
                    </p>
                    <details x-show="selected.length" class="text-sm text-tinta-suave" x-cloak>
                        <summary class="cursor-pointer">Ver números escolhidos</summary>
                        <ul class="mt-2 max-h-32 overflow-y-auto">
                            <template x-for="n in selected" :key="'s' + n">
                                <li class="flex items-center justify-between gap-4 py-0.5">
                                    <span>Número <span class="num font-semibold text-tinta" x-text="fmt(n)"></span> — <span class="num" x-text="brl(price)"></span></span>
                                    <button type="button" @click="toggle(n)" class="text-xs underline" :aria-label="'Remover o número ' + fmt(n)">remover</button>
                                </li>
                            </template>
                        </ul>
                    </details>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="clear()" x-show="selected.length" class="rounded-md px-3 py-2 text-sm font-semibold hover:underline" x-cloak>Limpar</button>
                    <button type="button" @click="submit()" class="btn btn-primary"
                        :disabled="selected.length < min || loading" :aria-busy="loading ? 'true' : 'false'">
                        <span x-text="loading ? 'Reservando…' : 'Reservar e continuar'"></span>
                    </button>
                </div>
                <p class="w-full text-xs text-tinta-suave" x-show="selected.length > 0 && selected.length < min" x-cloak>
                    Escolha pelo menos <span x-text="min"></span> números para continuar.
                </p>
            </div>
        </div>
    </div>

    @push('scripts')
        @livewireScripts
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('numberPicker', (c) => ({
                    ...c,
                    sold: new Set(),
                    reserved: new Set(),
                    selected: [],
                    page: 0,
                    pageSize: 500,
                    filter: 'all',
                    search: '',
                    randomCount: 1,
                    message: '',
                    messageKind: 'aviso',
                    loading: false,
                    offline: false,
                    updatedAt: '',
                    checkoutKey: crypto.randomUUID(),
                    cursor: null,
                    tabStop: null,

                    init() {
                        try { this.selected = JSON.parse(localStorage.getItem(this.storageKey) || '[]').filter((n) => n >= this.start && n <= this.end) } catch (e) {}
                        this.$watch('selected', (v) => { try { localStorage.setItem(this.storageKey, JSON.stringify(v)) } catch (e) {} })
                        this.refresh()
                        setInterval(() => this.refresh(), 5000)
                        window.addEventListener('online', () => this.refresh())
                        window.addEventListener('offline', () => { this.offline = true })
                    },

                    async refresh() {
                        try {
                            let r = await fetch(this.statusUrl, { headers: { Accept: 'application/json' } })
                            if (! r.ok) throw new Error(r.status)
                            let data = await r.json()
                            this.sold = new Set(data.sold)
                            this.reserved = new Set(data.reserved)
                            this.offline = false
                            this.updatedAt = new Date().toLocaleTimeString('pt-BR')
                            let lost = this.selected.filter((n) => this.sold.has(n) || this.reserved.has(n))
                            if (lost.length) {
                                this.selected = this.selected.filter((n) => ! lost.includes(n))
                                this.warn(lost.length === 1
                                    ? `O número ${this.fmt(lost[0])} acabou de ser reservado por outra pessoa e saiu da sua seleção.`
                                    : `Os números ${lost.map((n) => this.fmt(n)).join(', ')} acabaram de ser reservados por outras pessoas e saíram da sua seleção.`)
                            }
                            if (! data.open) this.warn('Esta promoção não está mais aceitando reservas.', 'erro')
                        } catch (e) {
                            this.offline = true
                        }
                    },

                    get total() { return this.end - this.start + 1 },
                    get pageCount() { return this.filter === 'all' ? Math.ceil(this.total / this.pageSize) : 1 },
                    get visible() {
                        if (this.filter === 'selected') return this.selected
                        if (this.filter === 'available') {
                            let out = []
                            for (let n = this.start; n <= this.end && out.length < 2000; n++) if (this.state(n) === 'livre') out.push(n)
                            return out
                        }
                        let from = this.start + this.page * this.pageSize
                        let to = Math.min(from + this.pageSize - 1, this.end)
                        let out = []
                        for (let n = from; n <= to; n++) out.push(n)
                        return out
                    },

                    state(n) {
                        if (this.sold.has(n)) return 'vendido'
                        if (this.reserved.has(n)) return 'reservado'
                        return this.selected.includes(n) ? 'minha' : 'livre'
                    },
                    stateLabel(n) {
                        return { vendido: 'vendido', reservado: 'reservado por outra pessoa', minha: 'escolhido por você', livre: 'disponível' }[this.state(n)]
                    },

                    toggle(n) {
                        let s = this.state(n)
                        if (s === 'vendido' || s === 'reservado') return this.warn(`O número ${this.fmt(n)} não está disponível.`)
                        if (s === 'minha') { this.selected = this.selected.filter((x) => x !== n); return }
                        if (this.selected.length >= this.max) return this.warn(`Você pode escolher no máximo ${this.max} números por pedido.`)
                        this.selected = [...this.selected, n].sort((a, b) => a - b)
                        this.message = ''
                    },

                    clear() { this.selected = []; this.message = '' },

                    move(e) {
                        let btns = [...e.currentTarget.querySelectorAll('button')]
                        let i = btns.indexOf(e.target)
                        if (i < 0) return
                        let cols = btns.filter((b) => b.offsetTop === btns[0].offsetTop).length
                        let j = { ArrowLeft: i - 1, ArrowRight: i + 1, ArrowUp: i - cols, ArrowDown: i + cols, Home: 0, End: btns.length - 1 }[e.key]
                        if (j === undefined || j < 0 || j >= btns.length) return
                        e.preventDefault()
                        btns[j].focus()
                    },

                    random() {
                        let want = Math.min(this.randomCount || 1, this.max - this.selected.length)
                        let free = []
                        for (let n = this.start; n <= this.end; n++) if (this.state(n) === 'livre') free.push(n)
                        if (want <= 0) return this.warn(`Você já escolheu o máximo de ${this.max} números.`)
                        if (free.length === 0) return this.warn('Não há números disponíveis.')
                        for (let i = free.length - 1; i > 0; i--) { let j = Math.floor(Math.random() * (i + 1)); [free[i], free[j]] = [free[j], free[i]] }
                        this.selected = [...this.selected, ...free.slice(0, want)].sort((a, b) => a - b)
                        this.message = ''
                    },

                    find() {
                        let n = parseInt(this.search, 10)
                        if (isNaN(n) || n < this.start || n > this.end) return this.warn(`Digite um número entre ${this.fmt(this.start)} e ${this.fmt(this.end)}.`)
                        this.filter = 'all'
                        this.page = Math.floor((n - this.start) / this.pageSize)
                        this.$nextTick(() => { let el = document.getElementById('n' + n); el?.scrollIntoView({ block: 'center' }); el?.focus() })
                    },

                    async submit() {
                        if (this.loading || this.selected.length < this.min) return
                        this.loading = true
                        this.message = ''
                        try {
                            let r = await fetch(this.reserveUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ numbers: this.selected, checkout_key: this.checkoutKey }),
                            })
                            let data = await r.json().catch(() => ({}))
                            if (r.ok && data.redirect) {
                                try { localStorage.removeItem(this.storageKey) } catch (e) {}
                                window.location = data.redirect
                                return
                            }
                            if (r.status === 409) {
                                this.selected = this.selected.filter((n) => ! data.conflicts.includes(n))
                                data.conflicts.forEach((n) => this.reserved.add(n))
                                this.reserved = new Set(this.reserved)
                            }
                            this.warn(r.status === 429 ? 'Muitas tentativas seguidas. Aguarde um minuto e tente de novo.'
                                : data.message || 'Não foi possível reservar agora. Tente de novo.', 'erro')
                        } catch (e) {
                            this.warn('Sem conexão. Seus números não foram reservados. Tente de novo.', 'erro')
                        } finally {
                            this.loading = false
                        }
                    },

                    warn(text, kind = 'aviso') { this.message = text; this.messageKind = kind },
                    fmt(n) { return String(n).padStart(this.digits, '0') },
                    brl(cents) { return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) },
                    rangeLabel(p) {
                        let from = this.start + p * this.pageSize
                        return this.fmt(from) + '–' + this.fmt(Math.min(from + this.pageSize - 1, this.end))
                    },
                }))
            })
        </script>
    @endpush
</x-layouts::public>
