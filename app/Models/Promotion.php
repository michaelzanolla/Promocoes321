<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Enums\PromotionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PromotionStatus $status
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $result_published_at
 * @property list<string> $payment_methods
 * @property int|null $sold_count preenchido por withNumberCounts()
 * @property int|null $series_start primeiro número da ação inteira (null = igual à faixa do site)
 * @property int|null $series_end
 * @property int|null $reserved_count preenchido por withNumberCounts()
 */
// status, resultado, is_demo e created_by ficam fora do Fillable: mudam só por ações específicas.
#[Fillable([
    'slug', 'title', 'summary', 'description', 'prize', 'category', 'image_path',
    'unit_price_cents', 'number_start', 'total_numbers', 'series_start', 'series_end', 'min_per_order', 'max_per_order',
    'allow_random', 'reservation_minutes', 'payment_methods', 'starts_at', 'ends_at',
    'rules', 'draw_info', 'legal_reference',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /** Campos que não podem mudar depois que existe qualquer pedido na promoção. */
    public const LOCKED_AFTER_FIRST_ORDER = ['unit_price_cents', 'number_start', 'total_numbers', 'series_start', 'series_end', 'payment_methods'];

    protected static function booted(): void
    {
        // A trava fica no model para valer em qualquer caminho (painel, console, código futuro).
        static::updating(function (Promotion $promotion) {
            if ($promotion->isDirty(self::LOCKED_AFTER_FIRST_ORDER) && $promotion->hasOrders()) {
                throw new DomainException('Preço, numeração e formas de pagamento não podem mudar depois do primeiro pedido.');
            }
        });
    }

    public function hasOrders(): bool
    {
        return $this->orders()->exists();
    }

    protected function casts(): array
    {
        return [
            'status' => PromotionStatus::class,
            'payment_methods' => 'array',
            'allow_random' => 'boolean',
            'is_demo' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'result_published_at' => 'datetime',
        ];
    }

    public function phase(): PromotionPhase
    {
        return match ($this->status) {
            PromotionStatus::Draft => PromotionPhase::Draft,
            PromotionStatus::Paused => PromotionPhase::Paused,
            PromotionStatus::Closed => PromotionPhase::Closed,
            PromotionStatus::Drawn => PromotionPhase::Drawn,
            PromotionStatus::Cancelled => PromotionPhase::Cancelled,
            PromotionStatus::Published => match (true) {
                $this->starts_at?->isFuture() ?? false => PromotionPhase::Scheduled,
                $this->ends_at?->isPast() ?? false => PromotionPhase::Closed,
                default => PromotionPhase::Open,
            },
        };
    }

    /**
     * Promoções que podem aparecer no site. Em produção, as fictícias nunca aparecem.
     *
     * @param  Builder<Promotion>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->whereIn('status', [
            PromotionStatus::Published, PromotionStatus::Paused, PromotionStatus::Closed, PromotionStatus::Drawn,
        ])->when(app()->isProduction(), fn (Builder $q) => $q->where('is_demo', false));
    }

    /**
     * Mesma regra de phase(), em SQL, para filtrar listagens.
     *
     * @param  Builder<Promotion>  $query
     */
    public function scopeInPhase(Builder $query, PromotionPhase $phase): void
    {
        $now = now();
        $published = fn (Builder $q) => $q->where('status', PromotionStatus::Published);

        match ($phase) {
            PromotionPhase::Scheduled => $query->where($published)->where('starts_at', '>', $now),
            PromotionPhase::Open => $query->where($published)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
            PromotionPhase::Closed => $query->where(fn (Builder $q) => $q
                ->where('status', PromotionStatus::Closed)
                ->orWhere(fn (Builder $q) => $q->where($published)->where('ends_at', '<=', $now))),
            default => $query->where('status', $phase->value),
        };
    }

    /**
     * Adiciona sold_count (pagos e confirmados) e reserved_count (reservas ainda válidas).
     *
     * @param  Builder<Promotion>  $query
     */
    public function scopeWithNumberCounts(Builder $query): void
    {
        $query->withCount([
            'items as sold_count' => fn (Builder $q) => $q->where('active', 1)
                ->whereHas('order', fn (Builder $o) => $o->where('status', OrderStatus::Confirmed)),
            // Ocupados sem estar pagos: reservas válidas, pagamentos em análise, pedidos em revisão
            // ou reembolsados (números reembolsados não voltam sozinhos para a venda).
            'items as reserved_count' => fn (Builder $q) => $q->where('active', 1)
                ->whereHas('order', fn (Builder $o) => $o
                    ->where('status', '!=', OrderStatus::Confirmed)
                    ->where(fn (Builder $w) => $w->where('status', '!=', OrderStatus::AwaitingPayment)->orWhere('reserved_until', '>', now()))),
        ]);
    }

    /** Último número vendido no site (a faixa online vai de number_start até aqui). */
    public function lastNumber(): int
    {
        return $this->number_start + $this->total_numbers - 1;
    }

    /** Primeiro número da ação inteira (pode ter começado fora do site). Sempre engloba a faixa do site. */
    public function seriesStart(): int
    {
        return min($this->series_start ?? $this->number_start, $this->number_start);
    }

    public function seriesEnd(): int
    {
        return max($this->series_end ?? $this->lastNumber(), $this->lastNumber());
    }

    /** O site vende só parte da numeração da ação. */
    public function sellsOnlyARange(): bool
    {
        return $this->seriesStart() !== $this->number_start || $this->seriesEnd() !== $this->lastNumber();
    }

    /** O número sorteado ficou fora da faixa do site ou foi marcado como vendido fora dele. */
    public function resultSoldOffline(): bool
    {
        if ($this->result_number === null) {
            return false;
        }

        return $this->result_number < $this->number_start || $this->result_number > $this->lastNumber()
            || $this->items()->where('number', $this->result_number)->where('active', 1)
                ->whereHas('order', fn (Builder $q) => $q->where('source', Order::SOURCE_OFFLINE))->exists();
    }

    /** 7 → "0007" quando a ação vai até 1000 (o zero à esquerda segue o maior número da ação). */
    public function formatNumber(int $number): string
    {
        return str_pad((string) $number, strlen((string) $this->seriesEnd()), '0', STR_PAD_LEFT);
    }

    public function availableCount(): int
    {
        return max(0, $this->total_numbers - (int) $this->sold_count - (int) $this->reserved_count);
    }

    public function soldPercent(): int
    {
        return $this->total_numbers > 0 ? (int) floor($this->sold_count * 100 / $this->total_numbers) : 0;
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<PromotionImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(PromotionImage::class)->orderBy('position');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
