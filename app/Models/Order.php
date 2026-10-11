<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrderStatus $status
 * @property PaymentMethod|null $payment_method
 * @property CarbonImmutable $reserved_until
 * @property CarbonImmutable|null $terms_accepted_at
 *
 * O pedido também representa a reserva dos números (reserved_until + status).
 * Sem Fillable: pedidos são criados só pelos serviços do servidor, nunca a partir do request.
 */
#[Hidden(['access_token_hash', 'buyer_cpf'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'terms_accepted_at' => 'datetime',
            'buyer_cpf' => 'encrypted',
            'reserved_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Reserva válida e aguardando pagamento: dá para identificar o comprador e pagar. */
    public function isPayable(): bool
    {
        return $this->status === OrderStatus::AwaitingPayment && $this->reserved_until->isFuture();
    }

    /** Já tem dados do comprador, forma de pagamento e aceite das regras. */
    public function isIdentified(): bool
    {
        return filled($this->buyer_name) && filled($this->buyer_email) && filled($this->buyer_cpf)
            && $this->payment_method !== null && $this->terms_accepted_at !== null;
    }

    public function matchesToken(string $token): bool
    {
        return $token !== '' && hash_equals($this->access_token_hash, hash('sha256', $token));
    }

    /**
     * Quem pode ver o pedido: o dono logado, um administrador ou quem tem o acesso guardado
     * na sessão (quem fez a reserva, ou abriu o link secreto do pedido).
     */
    public function isAccessibleBy(?User $user, Session $session): bool
    {
        return ($user && ($user->isAdmin() || $this->user_id === $user->id))
            || in_array($this->public_code, $session->get('orders.access', []), true);
    }

    public function grantAccessInSession(Session $session): void
    {
        $session->put('orders.access', array_values(array_unique([...$session->get('orders.access', []), $this->public_code])));
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
