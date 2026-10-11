<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $last_checked_at
 */
#[Hidden(['pix_qr_base64'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
