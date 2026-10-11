<a href="{{ route('home') }}" {{ $attributes->class('inline-flex items-center gap-2 text-tinta') }}>
    <x-app-logo-icon class="size-8 shrink-0" />
    <span class="font-display text-lg leading-none tracking-tight">{{ config('app.name') }}</span>
</a>
