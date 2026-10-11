<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Exceptions\NumbersUnavailable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reserva atômica de números (docs/fase-1-planejamento.md, seção 4).
 *
 * Quem impede a venda duplicada é a restrição única (promotion_id, number, active) em order_items:
 * se dois pedidos tentam o mesmo número ao mesmo tempo, o MySQL deixa só um gravar.
 * Ou todos os números do pedido são reservados, ou nenhum.
 */
class ReserveNumbers
{
    /** Teto de segurança, independente da configuração da promoção. */
    public const HARD_MAX_PER_ORDER = 1000;

    public function __construct(private ExpireOrders $expireOrders) {}

    /**
     * @param  list<int>  $numbers
     * @return array{order: Order, token: string} token: segredo do link de acesso ao pedido (guardado criptografado)
     *
     * @throws DomainException quando o pedido é inválido
     * @throws NumbersUnavailable quando algum número já foi reservado ou vendido
     */
    public function reserve(Promotion $promotion, array $numbers, string $checkoutKey, ?User $user = null): array
    {
        $numbers = $this->validate($promotion, $numbers);

        if ($existing = $this->existingForKey($promotion, $checkoutKey)) {
            return $existing;
        }

        $token = Str::random(40);

        try {
            $order = DB::transaction(function () use ($promotion, $numbers, $checkoutKey, $user, $token) {
                // Trava compartilhada: várias reservas convivem, mas o administrador não muda o status no meio delas.
                $locked = Promotion::whereKey($promotion->id)->sharedLock()->firstOrFail();
                if ($locked->phase() !== PromotionPhase::Open) {
                    throw new DomainException('Esta promoção não está aceitando reservas no momento.');
                }

                // Expiração preguiçosa: libera só os números disputados cujas reservas já venceram.
                $this->expireOrders->expireLocked($this->staleOrderIdsHolding($promotion, $numbers));

                $order = new Order;
                $order->forceFill([
                    'public_code' => $this->newPublicCode(),
                    'access_token' => $token,
                    'checkout_key' => $checkoutKey,
                    'promotion_id' => $promotion->id,
                    'user_id' => $user?->id,
                    'buyer_name' => $user?->name,
                    'buyer_email' => $user?->email,
                    'quantity' => count($numbers),
                    'unit_price_cents' => $locked->unit_price_cents,
                    'total_cents' => count($numbers) * $locked->unit_price_cents,
                    'status' => OrderStatus::AwaitingPayment,
                    'reserved_until' => now()->addMinutes($locked->reservation_minutes),
                ])->save();

                OrderItem::insert(array_map(fn (int $n) => [
                    'order_id' => $order->id,
                    'promotion_id' => $promotion->id,
                    'number' => $n,
                    'active' => 1,
                ], $numbers));

                return $order;
            }, attempts: 3); // repete em caso de deadlock entre reservas simultâneas
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // Corrida com o mesmo checkout_key (clique duplo): devolve o pedido que venceu.
            if (str_contains($e->getMessage(), 'checkout_key') && ($existing = $this->existingForKey($promotion, $checkoutKey))) {
                return $existing;
            }

            throw new NumbersUnavailable($this->takenAmong($promotion, $numbers));
        }

        return ['order' => $order, 'token' => $token];
    }

    /**
     * @param  list<mixed>  $numbers
     * @return list<int>
     */
    private function validate(Promotion $promotion, array $numbers): array
    {
        $clean = [];
        foreach ($numbers as $n) {
            if (filter_var($n, FILTER_VALIDATE_INT) === false) {
                throw new DomainException('Há números inválidos na seleção.');
            }
            $clean[(int) $n] = (int) $n;
        }
        $clean = array_values($clean);
        sort($clean);

        if ($clean === []) {
            throw new DomainException('Selecione pelo menos um número.');
        }

        foreach ($clean as $n) {
            if ($n < $promotion->number_start || $n > $promotion->lastNumber()) {
                throw new DomainException('O número '.$n.' não existe nesta promoção.');
            }
        }

        $max = min($promotion->max_per_order ?? self::HARD_MAX_PER_ORDER, self::HARD_MAX_PER_ORDER);
        if (count($clean) < $promotion->min_per_order) {
            throw new DomainException("Selecione pelo menos {$promotion->min_per_order} números.");
        }
        if (count($clean) > $max) {
            throw new DomainException("Selecione no máximo {$max} números por pedido.");
        }

        return $clean;
    }

    /**
     * Pedidos vencidos (ainda não limpos) que prendem algum dos números pedidos.
     *
     * @param  list<int>  $numbers
     * @return list<int>
     */
    private function staleOrderIdsHolding(Promotion $promotion, array $numbers): array
    {
        return array_values(OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.promotion_id', $promotion->id)
            ->whereIn('order_items.number', $numbers)
            ->where('order_items.active', 1)
            ->where('orders.status', OrderStatus::AwaitingPayment)
            ->where('orders.reserved_until', '<=', now())
            ->distinct()
            ->pluck('order_items.order_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * @param  list<int>  $numbers
     * @return list<int>
     */
    private function takenAmong(Promotion $promotion, array $numbers): array
    {
        return array_values(OrderItem::taken($promotion->id)->whereIn('order_items.number', $numbers)
            ->orderBy('order_items.number')->pluck('order_items.number')->map(fn ($n) => (int) $n)->all());
    }

    /**
     * @return array{order: Order, token: string}|null
     */
    private function existingForKey(Promotion $promotion, string $checkoutKey): ?array
    {
        $order = Order::where('checkout_key', $checkoutKey)->where('promotion_id', $promotion->id)->first();

        return $order ? ['order' => $order, 'token' => (string) $order->access_token] : null;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /** 10 caracteres sem letras/números ambíguos (sem 0/O, 1/I/L). */
    private function newPublicCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Order::where('public_code', $code)->exists());

        return $code;
    }
}
