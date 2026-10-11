<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-papel">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 px-4 py-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <x-brand class="self-center" wire:navigate />

                <div class="bilhete">
                    <div class="flex flex-col gap-6 p-6 sm:p-8">
                        {{ $slot }}
                    </div>
                    <div class="bilhete-canhoto px-6 py-3 text-center text-xs text-tinta-suave sm:px-8">
                        <a href="{{ route('home') }}" class="hover:text-tinta hover:underline">Voltar para as promoções</a>
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
