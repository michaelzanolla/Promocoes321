<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Mail\OrderMail;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

/**
 * @property OrderStatus $status
 * @property PaymentMethod|null $payment_method
 * @property CarbonImmutable $reserved_until
 * @property CarbonImmutable|null $terms_accepted_at
 *
 * O pedido também representa a reserva dos números (reserved_until + status).
 * Sem Fillable: pedidos são criados só pelos serviços do servidor, nunca a partir do request.
 */
#[Hidden(['access_token', 'buyer_cpf'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** Números marcados pelo administrador como vendidos fora do site (sem comprador, pagamento ou comprovante). */
    public const SOURCE_OFFLINE = 'offline';

    public function isOffline(): bool
    {
        return $this->source === self::SOURCE_OFFLINE;
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'terms_accepted_at' => 'datetime',
            'buyer_cpf' => 'encrypted',
            'access_token' => 'encrypted',
            'reserved_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
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
        return $token !== '' && hash_equals((string) $this->access_token, $token);
    }

    /**
     * Quem pode ver o pedido: o dono logado, quem tem conta com o mesmo e-mail já confirmado
     * (pedidos feitos como convidado), um administrador, ou quem tem o acesso guardado na sessão
     * (quem fez a reserva, ou abriu o link secreto do pedido).
     */
    public function isAccessibleBy(?User $user, Session $session): bool
    {
        return ($user && ($user->isAdmin() || $this->user_id === $user->id || $this->isOwnedByVerifiedEmailOf($user)))
            || in_array($this->public_code, $session->get('orders.access', []), true);
    }

    public function isOwnedByVerifiedEmailOf(User $user): bool
    {
        return $user->hasVerifiedEmail() && $this->buyer_email !== null
            && mb_strtolower($this->buyer_email) === mb_strtolower($user->email);
    }

    /**
     * Pedidos que aparecem em "Minhas compras": feitos logado ou como convidado com o mesmo e-mail confirmado.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('user_id', $user->id)
            ->when($user->hasVerifiedEmail(), fn (Builder $q) => $q->orWhere('buyer_email', mb_strtolower($user->email))));
    }

    public function grantAccessInSession(Session $session): void
    {
        $session->put('orders.access', array_values(array_unique([...$session->get('orders.access', []), $this->public_code])));
    }

    /** Link secreto do pedido (para e-mails). Pedidos antigos sem token ganham um agora. */
    public function secretUrl(): string
    {
        if (blank($this->access_token)) {
            $this->forceFill(['access_token' => Str::random(40)])->save();
        }

        return route('orders.show', ['code' => $this->public_code, 't' => $this->access_token]);
    }

    /**
     * Envia um e-mail do pedido depois que a resposta já foi entregue ao navegador.
     * Falha de e-mail nunca interrompe o fluxo (fica registrada no log).
     */
    public function sendMailLater(string $kind): void
    {
        if (blank($this->buyer_email)) {
            return;
        }

        $id = $this->id;
        defer(fn () => rescue(function () use ($id, $kind) {
            $order = self::findOrFail($id);
            Mail::to($order->buyer_email)->send(new OrderMail($order, $kind));
        }));
    }

    /** Nome para exibição pública: "Maria S." — nunca o nome completo. */
    public function maskedBuyerName(): string
    {
        // Só letras e espaços: "Fulano (apelido)" vira "Fulano A.", nunca "Fulano (.".
        $clean = trim((string) preg_replace('/[^\p{L}\s]+/u', ' ', (string) $this->buyer_name));
        $parts = $clean === '' ? [] : (preg_split('/\s+/', $clean) ?: []);
        $first = $parts[0] ?? '';

        if ($first === '') {
            return 'Comprador';
        }

        $last = count($parts) > 1 ? ' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : '';

        return mb_convert_case(mb_strtolower($first), MB_CASE_TITLE).$last;
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
