<?php

use App\Enums\PromotionPhase;
use App\Models\Promotion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'situacao', except: 'open')]
    public string $phase = 'open';

    #[Url(as: 'categoria', except: '')]
    public string $category = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'phase', 'category');
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Promotion>
     */
    #[Computed]
    public function promotions(): LengthAwarePaginator
    {
        $phase = PromotionPhase::tryFrom($this->phase);

        return Promotion::query()
            ->publiclyVisible()
            ->withNumberCounts()
            ->when($phase && in_array($phase, PromotionPhase::filterable(), true), fn ($q) => $q->inPhase($phase))
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('title', 'like', '%'.trim($this->search).'%'))
            ->orderByRaw('ends_at is null, ends_at')
            ->paginate(9);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function categories(): array
    {
        return Promotion::publiclyVisible()->whereNotNull('category')
            ->distinct()->orderBy('category')->pluck('category')->all();
    }

    public function filtered(): bool
    {
        return $this->search !== '' || $this->category !== '' || $this->phase !== 'open';
    }
};
?>

<div>
    <form role="search" wire:submit.prevent class="grid gap-3 rounded-(--radius-bilhete) border bg-cartao p-4 sm:grid-cols-[1fr_auto_auto]">
        <label class="grid gap-1 text-sm font-medium">
            Buscar pelo nome
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Ex.: smartphone"
                class="min-h-11 rounded-[10px] border bg-papel px-3 text-base focus:bg-cartao"
            >
        </label>

        <label class="grid gap-1 text-sm font-medium">
            Situação
            <select wire:model.live="phase" class="min-h-11 rounded-[10px] border bg-papel px-3 text-base">
                @foreach (\App\Enums\PromotionPhase::filterable() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
                <option value="all">Todas</option>
            </select>
        </label>

        <label class="grid gap-1 text-sm font-medium">
            Categoria
            <select wire:model.live="category" class="min-h-11 rounded-[10px] border bg-papel px-3 text-base">
                <option value="">Todas</option>
                @foreach ($this->categories as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
    </form>

    <p data-erro-lista hidden role="alert" class="mt-4 rounded-[10px] border border-carimbo/30 bg-carimbo-claro px-4 py-3 text-sm text-carimbo">
        Não foi possível atualizar a lista agora. Verifique sua conexão e tente de novo.
    </p>

    {{-- Anúncio para leitores de tela quando a lista muda. --}}
    <p class="sr-only" aria-live="polite" wire:loading.remove>
        {{ $this->promotions->total() }} {{ $this->promotions->total() === 1 ? 'promoção encontrada' : 'promoções encontradas' }}
    </p>
    <p class="sr-only" aria-live="polite" wire:loading>Carregando promoções…</p>

    <div class="relative mt-6">
        {{-- Carregando: esqueletos no lugar dos cards. --}}
        <div wire:loading.grid class="hidden gap-6 sm:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
            @for ($i = 0; $i < 3; $i++)
                <div class="bilhete animate-pulse">
                    <div class="aspect-[16/10] rounded-t-(--radius-bilhete) bg-zinc-100"></div>
                    <div class="space-y-3 p-5">
                        <div class="h-4 w-24 rounded bg-zinc-100"></div>
                        <div class="h-5 w-3/4 rounded bg-zinc-100"></div>
                        <div class="h-2 rounded bg-zinc-100"></div>
                    </div>
                </div>
            @endfor
        </div>

        <div wire:loading.remove>
            @if ($this->promotions->isEmpty())
                <div class="rounded-(--radius-bilhete) border border-dashed bg-cartao px-6 py-12 text-center">
                    @if ($this->filtered())
                        <p class="font-semibold">Nenhuma promoção encontrada com esses filtros.</p>
                        <p class="mt-1 text-sm text-tinta-suave">Tente outro nome ou veja todas as promoções.</p>
                        <button type="button" wire:click="clearFilters" class="btn btn-secondary mt-5">Limpar filtros</button>
                    @else
                        <p class="font-semibold">Nenhuma promoção aberta no momento.</p>
                        <p class="mt-1 text-sm text-tinta-suave">Novas promoções aparecem aqui assim que forem publicadas.</p>
                    @endif
                </div>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->promotions as $promotion)
                        <x-promotion-card :promotion="$promotion" wire:key="promo-{{ $promotion->id }}" />
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $this->promotions->links(data: ['scrollTo' => '#promocoes']) }}
                </div>
            @endif
        </div>
    </div>
</div>

@script
<script>
    // Falha de rede ou do servidor ao filtrar: mostra o aviso da lista em vez da tela de erro padrão.
    // A próxima resposta bem-sucedida redesenha o componente e o aviso volta a ficar escondido.
    $wire.$intercept(({ onError, onFailure }) => {
        let show = () => $wire.$el.querySelector('[data-erro-lista]').hidden = false
        onError(({ preventDefault }) => { preventDefault(); show() })
        onFailure(show)
    })
</script>
@endscript
