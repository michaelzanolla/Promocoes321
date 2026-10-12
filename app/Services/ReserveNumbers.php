<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Exceptions\NumbersUnavailable;
use App\Models\AuditLog;
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

    /** Pedidos aguardando pagamento ao mesmo tempo por pessoa (conta ou, sem conta, IP) em cada promoção. */
    public const MAX_OPEN_ORDERS = 3;

    public function __construct(private ExpireOrders $expireOrders) {}

    /**
     * @param  list<int>  $numbers
     * @return array{order: Order, token: string} token: segredo do link de acesso ao pedido (guardado criptografado)
     *
     * @throws DomainException quando o pedido é inválido
     * @throws NumbersUnavailable quando algum número já foi reservado ou vendido
     */
    public function reserve(Promotion $promotion, array $numbers, string $checkoutKey, ?User $user = null, ?string $ip = null): array
    {
        $numbers = $this->validate($promotion, $numbers);

        if ($existing = $this->existingForKey($promotion, $checkoutKey)) {
            return $existing;
        }

        $this->ensureOpenOrdersBelowLimit($promotion, $user, $ip);

        $token = Str::random(40);

        try {
            $order = DB::transaction(function () use ($promotion, $numbers, $checkoutKey, $user, $ip, $token) {
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
                    'reserved_ip' => $ip,
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

    /** Teto por marcação de números vendidos fora do site. */
    public const HARD_MAX_OFFLINE = 10000;

    /**
     * Marca números como vendidos fora do site (administrador). Usa a mesma trava única da reserva:
     * se algum já está reservado ou vendido no site, nada é marcado.
     *
     * @param  list<int>  $numbers
     *
     * @throws DomainException|NumbersUnavailable
     */
    public function markSoldOffline(Promotion $promotion, array $numbers, ?string $note = null): Order
    {
        foreach ($numbers as $n) {
            if ($n < $promotion->number_start || $n > $promotion->lastNumber()) {
                throw new DomainException('O número '.$promotion->formatNumber($n).' está fora da faixa vendida no site ('
                    .$promotion->formatNumber($promotion->number_start).' a '.$promotion->formatNumber($promotion->lastNumber()).').');
            }
        }

        try {
            $order = DB::transaction(function () use ($promotion, $numbers, $note) {
                $locked = Promotion::whereKey($promotion->id)->sharedLock()->firstOrFail();
                $this->ensureOfflineSalesAllowed($locked);
                $this->expireOrders->expireLocked($this->staleOrderIdsHolding($promotion, $numbers));

                $order = new Order;
                $order->forceFill([
                    'public_code' => $this->newPublicCode(),
                    'access_token' => Str::random(40),
                    'checkout_key' => (string) Str::uuid(),
                    'promotion_id' => $promotion->id,
                    'source' => Order::SOURCE_OFFLINE,
                    'internal_note' => filled($note) ? Str::limit(trim($note), 255, '') : null,
                    'buyer_name' => 'Venda fora do site',
                    'buyer_email' => '',
                    'buyer_cpf' => '',
                    'quantity' => count($numbers),
                    'unit_price_cents' => 0, // o valor foi recebido fora: não entra no financeiro do site
                    'total_cents' => 0,
                    'status' => OrderStatus::Confirmed,
                    'confirmed_at' => now(),
                    'reserved_until' => now(),
                ])->save();

                foreach (array_chunk($numbers, 1000) as $chunk) {
                    OrderItem::insert(array_map(fn (int $n) => [
                        'order_id' => $order->id, 'promotion_id' => $promotion->id, 'number' => $n, 'active' => 1,
                    ], $chunk));
                }

                return $order;
            }, attempts: 3);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            throw new NumbersUnavailable($this->takenAmong($promotion, $numbers));
        }

        AuditLog::record('order.offline_marked', $order, ['count' => count($numbers), 'note' => $order->internal_note]);

        return $order;
    }

    /** Desfaz uma marcação de venda fora do site: os números voltam a ficar disponíveis no site. */
    public function unmarkOffline(Order $order): void
    {
        if (! $order->isOffline() || $order->status !== OrderStatus::Confirmed) {
            throw new DomainException('Só marcações de venda fora do site podem ser desfeitas.');
        }

        DB::transaction(function () use ($order) {
            $this->ensureOfflineSalesAllowed(Promotion::whereKey($order->promotion_id)->sharedLock()->firstOrFail());
            OrderItem::where('order_id', $order->id)->update(['active' => null]);
            $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();
        });

        AuditLog::record('order.offline_unmarked', $order, ['count' => $order->quantity]);
    }

    /**
     * "507, 512, 600-650" → [507, 512, 600, 601, ..., 650].
     *
     * @return list<int>
     *
     * @throws DomainException
     */
    public static function parseList(string $input): array
    {
        $numbers = [];
        $normalized = (string) preg_replace('/\s*-\s*/', '-', trim($input));

        foreach (preg_split('/[\s,;]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', $part, $m) !== 1) {
                throw new DomainException("Não entendi \"{$part}\". Use números e faixas separados por vírgula, como 507, 512, 600-650.");
            }
            $from = (int) $m[1];
            $to = isset($m[2]) ? (int) $m[2] : $from;
            if ($to < $from) {
                throw new DomainException("A faixa \"{$part}\" está invertida: o primeiro número precisa ser o menor.");
            }
            if ($to - $from + 1 + count($numbers) > self::HARD_MAX_OFFLINE) {
                throw new DomainException('Marque no máximo '.self::HARD_MAX_OFFLINE.' números por vez.');
            }
            for ($n = $from; $n <= $to; $n++) {
                $numbers[$n] = $n;
            }
        }

        if ($numbers === []) {
            throw new DomainException('Informe pelo menos um número.');
        }
        ksort($numbers);

        return array_values($numbers);
    }

    /** Vendas fora do site só valem enquanto a ação não foi encerrada. */
    private function ensureOfflineSalesAllowed(Promotion $promotion): void
    {
        if (! in_array($promotion->phase(), [PromotionPhase::Draft, PromotionPhase::Scheduled, PromotionPhase::Open, PromotionPhase::Paused], true)) {
            throw new DomainException('Depois que a promoção é encerrada, os números vendidos fora do site não podem mais ser alterados.');
        }
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
     * Impede uma mesma pessoa de segurar muitos números com vários pedidos abertos.
     * ponytail: contado fora da transação; reservas simultâneas da mesma pessoa podem passar do limite
     * por poucas, já contidas pelo limite de 10 reservas por minuto.
     */
    private function ensureOpenOrdersBelowLimit(Promotion $promotion, ?User $user, ?string $ip): void
    {
        if (! $user && ! $ip) {
            return;
        }

        $open = Order::where('promotion_id', $promotion->id)
            ->where('status', OrderStatus::AwaitingPayment)
            ->where('reserved_until', '>', now())
            ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereNull('user_id')->where('reserved_ip', $ip))
            ->count();

        if ($open >= self::MAX_OPEN_ORDERS) {
            throw new DomainException('Você já tem '.self::MAX_OPEN_ORDERS.' pedidos aguardando pagamento nesta promoção. '
                .'Pague ou desista de um deles antes de reservar outros números.');
        }
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
