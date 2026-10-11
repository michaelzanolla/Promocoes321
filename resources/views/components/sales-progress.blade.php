@props(['promotion'])

@php($percent = $promotion->soldPercent())

<div {{ $attributes->class('space-y-1.5') }}>
    <div class="flex items-baseline justify-between gap-3 text-sm">
        <span class="text-tinta-suave">
            <span class="num font-medium text-tinta">{{ number_format($promotion->sold_count, 0, ',', '.') }}</span>
            de <span class="num">{{ number_format($promotion->total_numbers, 0, ',', '.') }}</span> vendidos
        </span>
        <span class="num font-semibold">{{ $percent }}%</span>
    </div>
    <div
        role="progressbar"
        aria-label="Números vendidos"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-valuenow="{{ $percent }}"
        class="h-2 overflow-hidden rounded-full bg-zinc-100"
    >
        <div class="h-full rounded-full bg-tinta" style="width: {{ $percent }}%"></div>
    </div>
</div>
