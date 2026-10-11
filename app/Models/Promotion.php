<?php

namespace App\Models;

use App\Enums\PromotionStatus;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
