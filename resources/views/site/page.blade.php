<x-layouts::public :title="$title">
    <article class="mx-auto max-w-2xl px-4 pt-12 sm:px-6">
        <h1 class="font-display text-3xl tracking-tight sm:text-4xl">{{ $title }}</h1>

        <div class="prose-pagina mt-8">
            {!! $html !!}
        </div>

        @if ($slug === 'contato')
            @php($contact = config('platform.contact'))
            <dl class="mt-8 grid gap-4 rounded-(--radius-bilhete) border bg-cartao p-6">
                <div>
                    <dt class="text-sm text-tinta-suave">E-mail</dt>
                    <dd class="font-semibold">
                        @if (filled($contact['email']))
                            <a class="underline" href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>
                        @else
                            Ainda não cadastrado.
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-tinta-suave">WhatsApp</dt>
                    <dd class="font-semibold">
                        @if (filled($contact['whatsapp']))
                            <a class="underline" href="https://wa.me/{{ preg_replace('/\D/', '', $contact['whatsapp']) }}" rel="noopener">{{ $contact['whatsapp'] }}</a>
                        @else
                            Ainda não cadastrado.
                        @endif
                    </dd>
                </div>
            </dl>
        @endif
    </article>
</x-layouts::public>
