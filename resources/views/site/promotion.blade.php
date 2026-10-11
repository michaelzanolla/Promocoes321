@php
    use App\Enums\PromotionPhase;
    use App\Support\Format;
    use Illuminate\Support\Str;

    $phase = $promotion->phase();
    $markdown = fn (?string $text) => Str::markdown((string) $text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    $organizer = ['name' => \App\Models\Setting::read('organizer_name'), 'document' => \App\Models\Setting::read('organizer_document'), 'city' => \App\Models\Setting::read('organizer_city')];
    $date = fn ($d) => $d->format('d/m/Y \à\s H\hi').' (horário de Brasília)';
@endphp

<x-layouts::public :title="$promotion->title">
    @if ($preview ?? false)
        <div role="status" class="border-b border-aviso/30 bg-aviso-claro">
            <p class="mx-auto max-w-6xl px-4 py-3 text-sm font-semibold text-aviso sm:px-6">
                Pré-visualização: esta promoção não está visível para o público ({{ $promotion->phase()->label() }}).
            </p>
        </div>
    @endif

    <div class="mx-auto max-w-6xl px-4 pt-8 sm:px-6">
        <nav aria-label="Você está em" class="text-sm text-tinta-suave">
            <a href="{{ route('home') }}#promocoes" class="hover:text-tinta hover:underline">Promoções</a>
            <span aria-hidden="true"> / </span>
            <span aria-current="page">{{ $promotion->title }}</span>
        </nav>

        <div class="mt-6 grid items-start gap-10 lg:grid-cols-[1.3fr_1fr]">
            <div class="space-y-4">
                <div class="aspect-[16/10] overflow-hidden rounded-(--radius-bilhete) border bg-talao-claro">
                    @if ($promotion->image_path)
                        <img src="{{ Storage::url($promotion->image_path) }}" alt="{{ $promotion->prize ?? $promotion->title }}" class="size-full object-cover">
                    @else
                        <div class="flex size-full flex-col items-center justify-center gap-2 text-tinta/70">
                            <span class="num text-xs tracking-[0.3em] uppercase">Números</span>
                            <span class="num text-4xl font-semibold">{{ $promotion->formatNumber($promotion->number_start) }}–{{ $promotion->formatNumber($promotion->lastNumber()) }}</span>
                        </div>
                    @endif
                </div>

                @if ($promotion->images->isNotEmpty())
                    <ul class="grid grid-cols-4 gap-3" aria-label="Galeria de imagens">
                        @foreach ($promotion->images as $image)
                            <li class="aspect-square overflow-hidden rounded-[10px] border">
                                <img src="{{ Storage::url($image->path) }}" alt="{{ $image->alt }}" class="size-full object-cover" loading="lazy">
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Resumo em forma de bilhete; fica fixo ao rolar em telas largas. --}}
            <aside class="bilhete lg:sticky lg:top-6" aria-labelledby="titulo-promocao">
                <div class="space-y-4 p-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-phase-badge :phase="$phase" />
                        @if ($promotion->category)
                            <span class="text-xs font-medium text-tinta-suave">{{ $promotion->category }}</span>
                        @endif
                    </div>

                    <h1 id="titulo-promocao" class="font-display text-2xl leading-tight tracking-tight sm:text-3xl">{{ $promotion->title }}</h1>

                    @if ($promotion->prize)
                        <p><span class="text-tinta-suave">Prêmio:</span> <span class="font-semibold">{{ $promotion->prize }}</span></p>
                    @endif

                    <x-sales-progress :promotion="$promotion" />

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt class="text-tinta-suave">Disponíveis agora</dt>
                            <dd class="num text-base font-semibold">{{ number_format($promotion->availableCount(), 0, ',', '.') }}</dd>
                        </div>
                        <div>
                            <dt class="text-tinta-suave">Números</dt>
                            <dd class="num text-base font-semibold">{{ $promotion->formatNumber($promotion->number_start) }} a {{ $promotion->formatNumber($promotion->lastNumber()) }}</dd>
                        </div>
                        @if ($phase === PromotionPhase::Scheduled && $promotion->starts_at)
                            <div class="col-span-2">
                                <dt class="text-tinta-suave">Começa em</dt>
                                <dd class="font-semibold"><time datetime="{{ $promotion->starts_at->toIso8601String() }}">{{ $date($promotion->starts_at) }}</time></dd>
                            </div>
                        @endif
                        <div class="col-span-2">
                            <dt class="text-tinta-suave">Encerramento</dt>
                            <dd class="font-semibold">
                                @if ($promotion->ends_at)
                                    <time datetime="{{ $promotion->ends_at->toIso8601String() }}">{{ $date($promotion->ends_at) }}</time>
                                @else
                                    Sem data definida
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($promotion->reserved_count > 0)
                        <p class="text-xs text-tinta-suave">
                            {{ $promotion->reserved_count }} {{ $promotion->reserved_count === 1 ? 'número está reservado' : 'números estão reservados' }} aguardando pagamento e pode{{ $promotion->reserved_count === 1 ? '' : 'm' }} voltar a ficar disponível.
                        </p>
                    @endif
                </div>

                <div class="bilhete-canhoto flex items-center justify-between gap-4 px-6 py-5">
                    <p class="leading-tight">
                        <span class="num block text-2xl font-semibold">{{ Format::brl($promotion->unit_price_cents) }}</span>
                        <span class="text-xs text-tinta-suave">por número</span>
                    </p>
                    @if ($phase === PromotionPhase::Open)
                        <a href="{{ route('numbers.show', $promotion->slug) }}" class="btn btn-primary">Escolher números</a>
                    @else
                        <p class="max-w-[12rem] text-right text-sm text-tinta-suave">
                            @switch($phase)
                                @case(PromotionPhase::Scheduled) A escolha de números abre quando a promoção começar. @break
                                @case(PromotionPhase::Paused) A promoção está pausada. Novas reservas estão suspensas. @break
                                @default Esta promoção não aceita novas participações.
                            @endswitch
                        </p>
                    @endif
                </div>
            </aside>
        </div>

        @if ($phase === PromotionPhase::Drawn && $promotion->result_number !== null)
            <section class="mt-12 rounded-(--radius-bilhete) border-2 border-tinta bg-talao-claro p-6" aria-labelledby="titulo-resultado">
                <h2 id="titulo-resultado" class="font-display text-2xl">Resultado</h2>
                <p class="mt-3">Número sorteado: <span class="num text-2xl font-semibold">{{ $promotion->formatNumber($promotion->result_number) }}</span></p>
                @if ($promotion->result_published_at)
                    <p class="text-sm text-tinta-suave">Publicado em {{ $date($promotion->result_published_at) }}</p>
                @endif
                @if ($promotion->result_proof_url)
                    <p class="mt-3"><a href="{{ $promotion->result_proof_url }}" class="underline" rel="noopener nofollow" target="_blank">Ver comprovação do sorteio</a></p>
                @endif
                @if ($promotion->result_notes)
                    <div class="prose-pagina mt-3 text-sm">{!! $markdown($promotion->result_notes) !!}</div>
                @endif
            </section>
        @endif

        <div class="mt-12 grid gap-12 lg:grid-cols-[1.3fr_1fr]">
            <div class="space-y-12">
                @if ($promotion->description)
                    <section aria-labelledby="titulo-descricao">
                        <h2 id="titulo-descricao" class="font-display text-xl">Sobre a promoção</h2>
                        <div class="prose-pagina mt-4">{!! $markdown($promotion->description) !!}</div>
                    </section>
                @endif

                <section aria-labelledby="titulo-regras">
                    <h2 id="titulo-regras" class="font-display text-xl">Regras de participação</h2>
                    <div class="prose-pagina mt-4">
                        @if ($promotion->rules)
                            {!! $markdown($promotion->rules) !!}
                        @else
                            <p class="text-tinta-suave">O responsável ainda não publicou as regras desta promoção.</p>
                        @endif
                    </div>
                    <p class="mt-4 text-sm text-tinta-suave">
                        @if ($promotion->max_per_order)
                            De {{ $promotion->min_per_order }} a {{ $promotion->max_per_order }} números por pedido.
                        @else
                            A partir de {{ $promotion->min_per_order }} {{ $promotion->min_per_order === 1 ? 'número' : 'números' }} por pedido.
                        @endif
                        Os números ficam reservados por {{ $promotion->reservation_minutes }} minutos enquanto o pagamento é feito.
                    </p>
                </section>

                <section aria-labelledby="titulo-apuracao">
                    <h2 id="titulo-apuracao" class="font-display text-xl">Como o resultado é apurado</h2>
                    <div class="prose-pagina mt-4">
                        @if ($promotion->draw_info)
                            {!! $markdown($promotion->draw_info) !!}
                        @else
                            <p class="text-tinta-suave">O responsável ainda não informou como o resultado será apurado.</p>
                        @endif
                    </div>
                </section>
            </div>

            <section aria-labelledby="titulo-responsavel" class="h-fit rounded-(--radius-bilhete) border bg-cartao p-6">
                <h2 id="titulo-responsavel" class="font-bold">Responsável pela promoção</h2>
                @if (filled($organizer['name']))
                    <p class="mt-2">{{ $organizer['name'] }}</p>
                    @if (filled($organizer['document']))
                        <p class="num text-sm text-tinta-suave">{{ $organizer['document'] }}</p>
                    @endif
                    @if (filled($organizer['city']))
                        <p class="text-sm text-tinta-suave">{{ $organizer['city'] }}</p>
                    @endif
                @else
                    <p class="mt-2 text-sm text-tinta-suave">Os dados do responsável ainda não foram cadastrados.</p>
                @endif
                @if ($promotion->legal_reference)
                    <p class="mt-4 text-sm"><span class="text-tinta-suave">Referência legal:</span> {{ $promotion->legal_reference }}</p>
                @endif
                <p class="mt-4 text-sm"><a href="{{ route('pages.show', 'contato') }}" class="underline">Falar com o responsável</a></p>
            </section>
        </div>
    </div>
</x-layouts::public>
