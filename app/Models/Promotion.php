<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Enums\PromotionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionFactory;
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
 * @property int|null $reserved_count preenchido por withNumberCounts()
 */
// status, resultado, is_demo e created_by ficam fora do Fillable: mudam só por ações específicas.
#[Fillable([
    'slug', 'title', 'summary', 'description', 'prize', 'category', 'image_path',
    'unit_price_cents', 'number_start', 'total_numbers', 'min_per_order', 'max_per_order',
    'allow_random', 'reservation_minutes', 'payment_methods', 'starts_at', 'ends_at',
    'rules', 'draw_info', 'legal_reference',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

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
            'items as reserved_count' => fn (Builder $q) => $q->where('active', 1)
                ->whereHas('order', fn (Builder $o) => $o
                    ->whereIn('status', [OrderStatus::AwaitingPayment, OrderStatus::Processing])
                    ->where('reserved_until', '>', now())),
        ]);
    }

    public function lastNumber(): int
    {
        return $this->number_start + $this->total_numbers - 1;
    }

    /** 7 → "0007" quando a promoção vai até 1000 (o zero à esquerda segue o maior número). */
    public function formatNumber(int $number): string
    {
        return str_pad((string) $number, strlen((string) $this->lastNumber()), '0', STR_PAD_LEFT);
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
