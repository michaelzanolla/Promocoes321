<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PromotionStatus;
use App\Exceptions\NumbersUnavailable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\User;
use App\Services\ExpireOrders;
use App\Services\ReserveNumbers;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReserveNumbersTest extends TestCase
{
    use RefreshDatabase;

    private ReserveNumbers $reserve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reserve = app(ReserveNumbers::class);
    }

    private function promo(array $attrs = []): Promotion
    {
        return Promotion::factory()->open()->create(['total_numbers' => 100, 'unit_price_cents' => 500, 'max_per_order' => 10, ...$attrs]);
    }

    public function test_reserves_numbers_and_computes_total_on_the_server(): void
    {
        $promotion = $this->promo();
        $user = User::factory()->create();

        ['order' => $order, 'token' => $token] = $this->reserve->reserve($promotion, [14, 12, '13', 12], (string) Str::uuid(), $user);

        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertSame(3, $order->quantity);
        $this->assertSame(1500, $order->total_cents);
        $this->assertSame([12, 13, 14], $order->items()->orderBy('number')->pluck('number')->all());
        $this->assertSame($user->id, $order->user_id);
        $this->assertTrue($order->reserved_until->between(now()->addMinutes(14), now()->addMinutes(16)));
        $this->assertTrue($order->matchesToken($token));
        $this->assertMatchesRegularExpression('/^[A-HJ-KM-NP-Z2-9]{10}$/', $order->public_code);
    }

    public function test_conflict_reserves_nothing_and_reports_taken_numbers(): void
    {
        $promotion = $this->promo();
        $this->reserve->reserve($promotion, [5, 6], (string) Str::uuid());

        try {
            $this->reserve->reserve($promotion, [4, 5, 6, 7], (string) Str::uuid());
            $this->fail('Deveria acusar conflito.');
        } catch (NumbersUnavailable $e) {
            $this->assertSame([5, 6], $e->numbers);
        }

        $this->assertSame(1, Order::count(), 'Nenhum pedido parcial pode sobrar.');
        $this->assertSame(0, OrderItem::whereIn('number', [4, 7])->count());
    }

    public function test_same_checkout_key_returns_the_same_order(): void
    {
        $promotion = $this->promo();
        $key = (string) Str::uuid();

        $first = $this->reserve->reserve($promotion, [1], $key)['order'];
        $second = $this->reserve->reserve($promotion, [1], $key)['order'];

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Order::count());
    }

    public function test_open_orders_per_person_are_limited(): void
    {
        $promotion = $this->promo();
        $user = User::factory()->create();
        $reserve = fn (int $n, ?User $u, string $ip, ?string $key = null) => $this->reserve->reserve($promotion, [$n], $key ?? (string) Str::uuid(), $u, $ip);

        $first = $reserve(1, null, '10.0.0.1')['order'];
        $reserve(2, null, '10.0.0.1');
        $reserve(3, null, '10.0.0.1');
        try {
            $reserve(4, null, '10.0.0.1');
            $this->fail('Deveria recusar o 4º pedido aberto do mesmo IP.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('3 pedidos aguardando pagamento', $e->getMessage());
        }

        // Repetir um pedido já feito (clique duplo) continua devolvendo o mesmo pedido.
        $this->assertSame($first->id, $reserve(1, null, '10.0.0.1', $first->checkout_key)['order']->id);
        // Outro IP, outra promoção e quem está logado (mesmo no mesmo IP) têm o próprio limite.
        $reserve(4, null, '10.0.0.2');
        $this->reserve->reserve($this->promo(), [1], (string) Str::uuid(), null, '10.0.0.1');
        foreach ([5, 6, 7] as $n) {
            $reserve($n, $user, '10.0.0.1');
        }
        $this->expectException(DomainException::class);
        $reserve(8, $user, '10.0.0.9');
    }

    public function test_guests_together_can_hold_at_most_half_of_the_promotion(): void
    {
        $promotion = $this->promo(['total_numbers' => 10]);
        $guest = fn (array $numbers, string $ip) => $this->reserve->reserve($promotion, $numbers, (string) Str::uuid(), null, $ip);

        $guest([1, 2, 3], '10.0.0.1');
        $guest([4, 5], '10.0.0.2');
        try {
            $guest([6], '10.0.0.3');
            $this->fail('Sem conta, ninguém pode passar de metade da promoção reservada.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Entre na sua conta', $e->getMessage());
        }

        // Quem está logado não entra nessa conta; pedido pago ou vencido libera espaço.
        $this->reserve->reserve($promotion, [6, 7], (string) Str::uuid(), User::factory()->create());
        Order::whereNull('user_id')->where('reserved_ip', '10.0.0.2')->update(['status' => OrderStatus::Confirmed]);
        $guest([8, 9], '10.0.0.3');
        $this->assertSame(2, Order::whereNull('user_id')->where('status', OrderStatus::AwaitingPayment)->count());
    }

    public function test_paid_cancelled_or_expired_orders_do_not_count_toward_the_limit(): void
    {
        $promotion = $this->promo();
        $orders = collect([1, 2, 3])->map(fn ($n) => $this->reserve->reserve($promotion, [$n], (string) Str::uuid(), null, '10.0.0.1')['order']);

        $orders[0]->forceFill(['status' => OrderStatus::Confirmed])->save();
        $orders[1]->forceFill(['status' => OrderStatus::Cancelled])->save();
        $orders[2]->forceFill(['reserved_until' => now()->subMinute()])->save(); // vencido, ainda não limpo

        foreach ([4, 5, 6] as $n) {
            $this->reserve->reserve($promotion, [$n], (string) Str::uuid(), null, '10.0.0.1');
        }
        $this->assertSame(6, Order::where('reserved_ip', '10.0.0.1')->count());
    }

    public function test_expired_reservation_numbers_can_be_taken_again(): void
    {
        $promotion = $this->promo();
        $old = $this->reserve->reserve($promotion, [20, 21], (string) Str::uuid())['order'];
        $old->forceFill(['reserved_until' => now()->subSecond()])->save();

        $new = $this->reserve->reserve($promotion, [21], (string) Str::uuid())['order'];

        $this->assertSame(OrderStatus::Expired, $old->fresh()->status);
        $this->assertSame(0, OrderItem::where('order_id', $old->id)->where('active', 1)->count());
        $this->assertSame(2, OrderItem::where('order_id', $old->id)->count(), 'O histórico continua guardado.');
        $this->assertSame([21], $new->items()->pluck('number')->all());
    }

    public function test_paid_or_processing_numbers_are_never_released_by_expiration(): void
    {
        $promotion = $this->promo();
        $order = $this->reserve->reserve($promotion, [30], (string) Str::uuid())['order'];
        $order->forceFill(['status' => OrderStatus::Processing, 'reserved_until' => now()->subHour()])->save();

        app(ExpireOrders::class)->expireAllDue();

        $this->expectException(NumbersUnavailable::class);
        $this->reserve->reserve($promotion, [30], (string) Str::uuid());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<mixed>}>
     */
    public static function invalidRequests(): array
    {
        return [
            'fora da faixa' => [[], [0]],
            'acima do último' => [[], [101]],
            'texto' => [[], ['abc']],
            'vazio' => [[], []],
            'acima do máximo' => [['max_per_order' => 2], [1, 2, 3]],
            'abaixo do mínimo' => [['min_per_order' => 3], [1, 2]],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_are_rejected(array $attrs, array $numbers): void
    {
        $this->expectException(DomainException::class);
        $this->reserve->reserve($this->promo($attrs), $numbers, (string) Str::uuid());
    }

    public function test_only_open_promotions_accept_reservations(): void
    {
        foreach ([
            ['status' => PromotionStatus::Paused],
            ['status' => PromotionStatus::Closed],
            ['starts_at' => now()->addDay()],
            ['ends_at' => now()->subMinute()],
        ] as $attrs) {
            try {
                $this->reserve->reserve($this->promo($attrs), [1], (string) Str::uuid());
                $this->fail('Deveria recusar: '.json_encode($attrs));
            } catch (DomainException) {
            }
        }

        $this->assertSame(0, Order::count());
    }

    public function test_scheduler_command_expires_due_orders(): void
    {
        $promotion = $this->promo();
        $due = $this->reserve->reserve($promotion, [40], (string) Str::uuid())['order'];
        $due->forceFill(['reserved_until' => now()->subMinute()])->save();
        $valid = $this->reserve->reserve($promotion, [41], (string) Str::uuid())['order'];

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $due->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $valid->fresh()->status);
    }
}
