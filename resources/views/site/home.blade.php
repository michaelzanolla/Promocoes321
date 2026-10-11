<x-layouts::public>
    {{-- Hero: o bilhete é a tese da página. Textos editáveis em Painel > Configurações. --}}
    <section class="overflow-hidden">
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 pt-14 pb-16 sm:px-6 md:grid-cols-[1.1fr_1fr] md:pt-20 md:pb-24">
            <div>
                <h1 class="font-display text-[2.6rem] leading-[1.02] tracking-tight sm:text-6xl">
                    {{ \App\Models\Setting::read('hero_title') }}<br>
                    <span class="box-decoration-clone bg-[linear-gradient(transparent_68%,var(--color-talao)_68%,var(--color-talao)_92%,transparent_92%)]">{{ \App\Models\Setting::read('hero_highlight') }}</span>
                </h1>
                <p class="mt-6 max-w-md text-lg text-tinta-suave">{{ \App\Models\Setting::read('hero_text') }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="#promocoes" class="btn btn-primary">Ver promoções</a>
                    <a href="#como-funciona" class="btn btn-secondary">Como funciona</a>
                </div>
            </div>

            <div class="relative mx-auto w-full max-w-md" aria-hidden="true">
                <div class="bilhete bilhete-horizontal hero-bilhete -rotate-3 shadow-[0_24px_48px_-24px_rgb(18_52_59/0.35)]">
                    <div class="p-6 sm:p-8">
                        <p class="num text-[0.7rem] tracking-[0.3em] text-tinta-suave uppercase">Ação entre amigos</p>
                        <p class="mt-5 text-sm text-tinta-suave">Seu número</p>
                        <p class="num text-6xl leading-none font-semibold sm:text-7xl">0042</p>
                        <div class="mt-6 grid grid-cols-5 gap-1.5">
                            @foreach (['0040', '0041', '0042', '0043', '0044'] as $n)
                                <span @class([
                                    'num rounded-md border py-1.5 text-center text-xs',
                                    'border-tinta bg-talao font-semibold' => $n === '0042',
                                    'text-tinta-suave' => $n !== '0042',
                                ])>{{ $n }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="bilhete-canhoto flex items-center justify-center px-6 py-4 sm:w-28 sm:flex-col sm:py-8">
                        <p class="num text-center text-xs leading-relaxed text-tinta-suave sm:[writing-mode:vertical-rl] sm:rotate-180">
                            Nº 0042 · canhoto
                        </p>
                    </div>
                </div>
                <span class="hero-carimbo absolute -top-4 right-2 rotate-[14deg] rounded-md border-[3px] border-carimbo px-3 py-1 font-display text-xl tracking-wider text-carimbo uppercase sm:right-6">
                    Confirmado
                </span>
            </div>
        </div>
    </section>

    <section id="promocoes" class="scroll-mt-6 border-t bg-cartao/60">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="mb-8 max-w-xl">
                <h2 class="font-display text-3xl tracking-tight">Promoções</h2>
                <p class="mt-2 text-tinta-suave">Os números vendidos são atualizados a cada visita. A disponibilidade final é confirmada quando você reserva.</p>
            </div>

            <livewire:promotion-list />
        </div>
    </section>

    <section id="como-funciona" class="scroll-mt-6">
        <div class="mx-auto max-w-6xl px-4 pt-20 sm:px-6">
            <h2 class="font-display text-3xl tracking-tight">Como funciona</h2>

            @php
                $steps = [
                    ['Escolha uma promoção', 'Veja o prêmio, o valor de cada número, a data de encerramento e as regras.'],
                    ['Selecione seus números', 'Marque um ou vários números livres na grade, ou peça números aleatórios quando a promoção permitir.'],
                    ['Faça o pagamento', 'Pague por Pix ou cartão. Seus números ficam reservados para você enquanto o pagamento é feito.'],
                    ['Receba seu comprovante', 'Com o pagamento confirmado, o comprovante lista os seus números e pode ser conferido por qualquer pessoa.'],
                ];
            @endphp

            <ol class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($steps as $i => [$title, $text])
                    <li class="bilhete">
                        <div class="p-5">
                            <h3 class="font-bold">{{ $title }}</h3>
                            <p class="mt-2 text-sm text-tinta-suave">{{ $text }}</p>
                        </div>
                        <div class="bilhete-canhoto px-5 py-3">
                            <p class="num text-xs text-tinta-suave">Etapa <span class="font-semibold text-tinta">{{ $i + 1 }}</span> de {{ count($steps) }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
</x-layouts::public>
