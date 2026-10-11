@props(['promotion'])

@php
    use App\Enums\PromotionPhase;
    use App\Support\Format;

    $phase = $promotion->phase();
    $url = route('promotions.show', $promotion->slug);
@endphp

<article {{ $attributes->class('bilhete group overflow-visible') }}>
    <div class="flex flex-col">
        <div class="aspect-[16/10] overflow-hidden rounded-t-(--radius-bilhete) bg-talao-claro">
            @if ($promotion->image_path)
                <img src="{{ Storage::url($promotion->image_path) }}" alt="" class="size-full object-cover" loading="lazy">
            @else
                <div class="flex size-full flex-col items-center justify-center gap-1 text-tinta/70" aria-hidden="true">
                    <span class="num text-xs tracking-[0.3em] uppercase">Números</span>
                    <span class="num text-2xl font-semibold">{{ $promotion->formatNumber($promotion->number_start) }}–{{ $promotion->formatNumber($promotion->lastNumber()) }}</span>
                </div>
            @endif
        </div>

        <div class="flex flex-1 flex-col gap-3 p-5">
            <div class="flex flex-wrap items-center gap-2">
                <x-phase-badge :phase="$phase" />
                @if ($promotion->category)
                    <span class="text-xs font-medium text-tinta-suave">{{ $promotion->category }}</span>
                @endif
            </div>

            <h3 class="text-lg leading-snug font-bold">
                <a href="{{ $url }}" class="after:absolute after:inset-0 after:rounded-(--radius-bilhete) hover:underline">{{ $promotion->title }}</a>
            </h3>

            @if ($promotion->summary)
                <p class="line-clamp-2 text-sm text-tinta-suave">{{ $promotion->summary }}</p>
            @endif

            @if ($promotion->prize)
                <p class="text-sm"><span class="text-tinta-suave">Prêmio:</span> <span class="font-semibold">{{ $promotion->prize }}</span></p>
            @endif

            <x-sales-progress :promotion="$promotion" class="mt-auto pt-2" />

            <p class="text-xs text-tinta-suave">
                @if ($phase === PromotionPhase::Scheduled && $promotion->starts_at)
                    Começa em <time datetime="{{ $promotion->starts_at->toIso8601String() }}">{{ $promotion->starts_at->format('d/m/Y \à\s H\hi') }}</time>
                @elseif ($promotion->ends_at)
                    {{ $phase === PromotionPhase::Open ? 'Encerra em' : 'Encerrou em' }}
                    <time datetime="{{ $promotion->ends_at->toIso8601String() }}">{{ $promotion->ends_at->format('d/m/Y \à\s H\hi') }}</time>
                @else
                    Sem data de encerramento definida
                @endif
            </p>
        </div>
    </div>

    <div class="bilhete-canhoto flex items-center justify-between gap-4 px-5 py-4">
        <p class="leading-tight">
            <span class="num block text-xl font-semibold">{{ Format::brl($promotion->unit_price_cents) }}</span>
            <span class="text-xs text-tinta-suave">por número</span>
        </p>
        {{-- relative z-10: fica acima do link "esticado" do título. --}}
        @if ($phase === PromotionPhase::Open)
            <a href="{{ $url }}" class="btn btn-primary relative z-10">Participar</a>
        @else
            <a href="{{ $url }}" class="btn btn-secondary relative z-10">Ver detalhes</a>
        @endif
    </div>
</article>
