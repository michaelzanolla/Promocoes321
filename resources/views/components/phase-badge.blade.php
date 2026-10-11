@props(['phase'])

@php
    use App\Enums\PromotionPhase;

    [$icon, $classes] = match ($phase) {
        PromotionPhase::Open => ['check-circle', 'bg-ok-claro text-ok'],
        PromotionPhase::Scheduled => ['clock', 'bg-aviso-claro text-aviso'],
        PromotionPhase::Paused => ['pause-circle', 'bg-aviso-claro text-aviso'],
        PromotionPhase::Closed => ['lock-closed', 'bg-zinc-100 text-tinta-suave'],
        PromotionPhase::Drawn => ['trophy', 'bg-talao-claro text-tinta'],
        PromotionPhase::Cancelled => ['x-circle', 'bg-carimbo-claro text-carimbo'],
        PromotionPhase::Draft => ['pencil-square', 'bg-zinc-100 text-tinta-suave'],
    };
@endphp

{{-- Ícone + texto: o estado nunca depende só da cor. --}}
<span {{ $attributes->class("inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold $classes") }}>
    <flux:icon :name="$icon" variant="micro" />
    {{ $phase->label() }}
</span>
