<x-layouts::public title="Consultar pedido">
    <div class="mx-auto max-w-lg px-4 pt-12 sm:px-6">
        <h1 class="font-display text-3xl tracking-tight">Consultar pedido</h1>
        <p class="mt-2 text-tinta-suave">
            Informe o código do pedido e o e-mail usado na compra. Enviamos para esse e-mail um link de acesso ao pedido e ao comprovante.
        </p>

        @if (session('status'))
            <p role="status" class="mt-6 rounded-[10px] border border-ok/30 bg-ok-claro px-4 py-3 text-sm text-ok">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('orders.lookup.store') }}" class="mt-6 grid gap-5 rounded-(--radius-bilhete) border bg-cartao p-6">
            @csrf
            <label class="grid gap-1 text-sm font-medium">
                Código do pedido
                <input name="code" required maxlength="20" autocomplete="off" value="{{ old('code') }}" placeholder="Ex.: AB2CD3EF4G"
                    class="num min-h-11 rounded-[10px] border bg-papel px-3 text-base uppercase focus:bg-cartao" aria-describedby="erro-codigo">
                @error('code') <span id="erro-codigo" class="text-carimbo">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1 text-sm font-medium">
                E-mail usado na compra
                <input name="email" type="email" required autocomplete="email" value="{{ old('email') }}"
                    class="min-h-11 rounded-[10px] border bg-papel px-3 text-base focus:bg-cartao" aria-describedby="erro-email">
                @error('email') <span id="erro-email" class="text-carimbo">{{ $message }}</span> @enderror
            </label>
            <div>
                <button type="submit" class="btn btn-primary">Enviar link por e-mail</button>
            </div>
        </form>

        <p class="mt-6 text-sm text-tinta-suave">
            Tem conta? <a href="{{ route('login') }}" class="underline">Entre</a> para ver todas as suas compras.
            Para conferir um comprovante, leia o QR Code impresso nele.
        </p>
    </div>
</x-layouts::public>
