@props(['title' => null])

@php
    $nav = [
        ['label' => 'Início', 'href' => route('home')],
        ['label' => 'Promoções', 'href' => route('home').'#promocoes'],
        ['label' => 'Como funciona', 'href' => route('home').'#como-funciona'],
    ];
    $organizer = ['name' => \App\Models\Setting::read('organizer_name'), 'document' => \App\Models\Setting::read('organizer_document'), 'city' => \App\Models\Setting::read('organizer_city')];
    $contact = ['email' => \App\Models\Setting::read('contact_email'), 'whatsapp' => \App\Models\Setting::read('contact_whatsapp')];
@endphp

<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="flex min-h-screen flex-col">
        <a href="#conteudo" class="btn btn-primary sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50">
            Pular para o conteúdo
        </a>

        <header class="border-b bg-cartao/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-6 px-4 sm:px-6">
                <x-brand />

                <nav aria-label="Principal" class="hidden items-center gap-1 md:flex">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}" class="rounded-md px-3 py-2 text-[0.95rem] font-medium text-tinta-suave hover:text-tinta">{{ $item['label'] }}</a>
                    @endforeach
                </nav>

                <div class="hidden items-center gap-2 md:flex">
                    @auth
                        @can('access-admin')
                            <a href="{{ url('/admin') }}" class="rounded-md px-3 py-2 font-semibold hover:underline">Painel</a>
                        @endcan
                        <a href="{{ route('dashboard') }}" class="btn btn-secondary">Minha conta</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-md px-3 py-2 font-semibold hover:underline">Entrar</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-primary">Cadastrar-se</a>
                        @endif
                    @endauth
                </div>

                {{-- Menu do celular: <details> nativo, funciona sem JavaScript e com teclado. --}}
                <details class="group relative md:hidden">
                    <summary class="btn btn-secondary list-none px-3 [&::-webkit-details-marker]:hidden">
                        <span class="group-open:hidden">Menu</span>
                        <span class="hidden group-open:inline">Fechar</span>
                    </summary>
                    <div class="absolute right-0 z-40 mt-2 w-64 rounded-(--radius-bilhete) border bg-cartao p-2 shadow-lg">
                        <nav aria-label="Principal (celular)" class="grid">
                            @foreach ($nav as $item)
                                <a href="{{ $item['href'] }}" class="rounded-md px-3 py-3 font-medium hover:bg-papel">{{ $item['label'] }}</a>
                            @endforeach
                        </nav>
                        <div class="mt-2 grid gap-2 border-t pt-3">
                            @auth
                                <a href="{{ route('dashboard') }}" class="btn btn-secondary">Minha conta</a>
                            @else
                                <a href="{{ route('login') }}" class="btn btn-secondary">Entrar</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="btn btn-primary">Cadastrar-se</a>
                                @endif
                            @endauth
                        </div>
                    </div>
                </details>
            </div>
        </header>

        <main id="conteudo" class="flex-1">
            {{ $slot }}
        </main>

        <footer class="mt-24 border-t bg-cartao">
            <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr_1.2fr]">
                <div class="space-y-3">
                    <x-brand />
                    <p class="max-w-xs text-sm text-tinta-suave">
                        Ações entre amigos com números escolhidos por você, pagamento por Pix ou cartão e comprovante que pode ser conferido por qualquer pessoa.
                    </p>
                </div>

                <nav aria-label="Institucional" class="text-sm">
                    <h2 class="mb-3 font-semibold">Institucional</h2>
                    <ul class="space-y-2 text-tinta-suave">
                        <li><a class="hover:text-tinta hover:underline" href="{{ route('pages.show', 'sobre') }}">Sobre a plataforma</a></li>
                        <li><a class="hover:text-tinta hover:underline" href="{{ route('home') }}#como-funciona">Como funciona</a></li>
                        <li><a class="hover:text-tinta hover:underline" href="{{ route('pages.show', 'contato') }}">Contato e suporte</a></li>
                    </ul>
                </nav>

                <nav aria-label="Regras e políticas" class="text-sm">
                    <h2 class="mb-3 font-semibold">Regras e políticas</h2>
                    <ul class="space-y-2 text-tinta-suave">
                        <li><a class="hover:text-tinta hover:underline" href="{{ route('pages.show', 'termos-de-uso') }}">Termos de uso</a></li>
                        <li><a class="hover:text-tinta hover:underline" href="{{ route('pages.show', 'privacidade') }}">Política de privacidade</a></li>
                        <li><a class="hover:text-tinta hover:underline" href="{{ route('pages.show', 'cancelamento-e-reembolso') }}">Cancelamento e reembolso</a></li>
                        <li><a class="hover:text-tinta hover:underline" href="{{ route('pages.show', 'regras') }}">Regras e informações legais</a></li>
                    </ul>
                </nav>

                <div class="text-sm">
                    <h2 class="mb-3 font-semibold">Responsável pelas promoções</h2>
                    @if (filled($organizer['name']))
                        <p>{{ $organizer['name'] }}</p>
                        @if (filled($organizer['document']))
                            <p class="num text-tinta-suave">{{ $organizer['document'] }}</p>
                        @endif
                        @if (filled($organizer['city']))
                            <p class="text-tinta-suave">{{ $organizer['city'] }}</p>
                        @endif
                    @else
                        <p class="text-tinta-suave">Os dados do responsável ainda não foram cadastrados.</p>
                    @endif
                    @if (filled($contact['email']))
                        <p class="mt-3"><a class="underline" href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></p>
                    @endif
                </div>
            </div>
            <div class="border-t">
                <p class="mx-auto max-w-6xl px-4 py-5 text-xs text-tinta-suave sm:px-6">
                    © {{ now()->year }} {{ config('app.name') }}. Participe com responsabilidade.
                </p>
            </div>
        </footer>
    </body>
</html>
