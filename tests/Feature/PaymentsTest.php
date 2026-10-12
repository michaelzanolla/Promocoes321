<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Promotion;
use App\Models\User;
use App\Payments\RefundOrder;
use App\Payments\StartPayment;
use App\Services\ExpireOrders;
use App\Services\PromotionLifecycle;
use App\Services\ReserveNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integração de pagamento com o Asaas simulado. Nenhuma chamada real sai durante os testes.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-sandbox.asaas.com/v3';

    /** Cobranças "no Asaas" simulado: id => dados devolvidos pela API. */
    private array $remote = [];

    private Promotion $promotion;

    /** Simula o Asaas fora do ar. */
    private bool $providerDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.asaas.api_key' => 'chave-de-teste', 'services.asaas.webhook_token' => 'token-webhook', 'services.asaas.base_url' => self::BASE]);
        $this->promotion = Promotion::factory()->open()->create(['unit_price_cents' => 500, 'total_numbers' => 50, 'payment_methods' => ['pix', 'credit_card']]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($this->providerDown) {
                return Http::response(['errors' => [['description' => 'erro interno']]], 500);
            }

            $path = Str::after($request->url(), self::BASE);
            $path = Str::before($path, '?');

            return match (true) {
                $request->method() === 'POST' && $path === '/customers' => Http::response(['id' => 'cus_1']),
                $request->method() === 'POST' && $path === '/payments' => $this->createRemote($request->data()),
                $request->method() === 'GET' && str_ends_with($path, '/pixQrCode') => Http::response(['payload' => '00020126PIXCOPIAECOLA', 'encodedImage' => base64_encode('png')]),
                $request->method() === 'GET' && str_starts_with($path, '/payments/') => isset($this->remote[Str::after($path, '/payments/')])
                    ? Http::response($this->remote[Str::after($path, '/payments/')])
                    : Http::response(['errors' => [['description' => 'não encontrada']]], 404),
                $request->method() === 'DELETE' && str_starts_with($path, '/payments/') => $this->setRemote(Str::after($path, '/payments/'), ['deleted' => true]),
                $request->method() === 'POST' && str_ends_with($path, '/refund') => $this->setRemote(Str::between($path, '/payments/', '/refund'), ['status' => 'REFUNDED']),
                default => Http::response(['errors' => [['description' => "rota não simulada: {$request->method()} {$path}"]]], 500),
            };
        });
    }

    private function createRemote(array $data)
    {
        $id = 'pay_'.(count($this->remote) + 1);
        $this->remote[$id] = [
            'id' => $id, 'status' => 'PENDING', 'value' => $data['value'], 'externalReference' => $data['externalReference'],
            'billingType' => $data['billingType'], 'dueDate' => $data['dueDate'], 'invoiceUrl' => "https://sandbox.asaas.com/i/{$id}",
        ];

        return Http::response($this->remote[$id]);
    }

    private function setRemote(string $id, array $changes)
    {
        $this->remote[$id] = [...$this->remote[$id], ...$changes];

        return Http::response($this->remote[$id]);
    }

    /** Pedido reservado, com dados confirmados e acesso na sessão. */
    private function identifiedOrder(array $numbers = [1, 2, 3], PaymentMethod $method = PaymentMethod::Pix): Order
    {
        $order = app(ReserveNumbers::class)->reserve($this->promotion, $numbers, (string) Str::uuid())['order'];
        $order->forceFill([
            'buyer_name' => 'Maria da Silva', 'buyer_email' => 'maria@example.com', 'buyer_cpf' => '52998224725',
            'payment_method' => $method, 'terms_accepted_at' => now(),
        ])->save();
        $this->withSession(['orders.access' => [$order->public_code]]);

        return $order;
    }

    private function webhook(string $eventId, string $event, string $chargeId, string $token = 'token-webhook')
    {
        return $this->postJson(route('webhooks.asaas'), ['id' => $eventId, 'event' => $event, 'payment' => ['id' => $chargeId, 'status' => 'RECEIVED']], ['asaas-access-token' => $token]);
    }

    public function test_generates_pix_once_even_with_repeated_clicks(): void
    {
        $order = $this->identifiedOrder();

        $this->post(route('orders.pay', $order->public_code))->assertRedirect(route('orders.show', $order->public_code));
        $this->post(route('orders.pay', $order->public_code));

        $payment = $order->payments()->sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('00020126PIXCOPIAECOLA', $payment->pix_payload);
        $this->assertSame(1500, $payment->amount_cents);

        Http::assertSentCount(3); // cria cliente, cria cobrança, QR Code; o 2º clique não chama o Asaas
        // Cliente sempre com os dados deste pedido: nunca o cadastro de quem já usou o mesmo CPF.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers')
            && $r['name'] === 'Maria da Silva' && $r['email'] === 'maria@example.com' && $r['cpfCnpj'] === '52998224725');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/payments')
            && $r['value'] == 15.0 && $r['externalReference'] === $order->public_code && $r['billingType'] === 'PIX'
            && $r->hasHeader('access_token', 'chave-de-teste'));

        $this->get(route('orders.show', $order->public_code))->assertSee('Copiar código')->assertSee('00020126PIXCOPIAECOLA');
    }

    public function test_card_goes_to_the_provider_hosted_page(): void
    {
        $order = $this->identifiedOrder([4], PaymentMethod::CreditCard);

        $this->post(route('orders.pay', $order->public_code))->assertRedirect('https://sandbox.asaas.com/i/pay_1');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/payments') && $r['billingType'] === 'CREDIT_CARD');
    }

    public function test_provider_failure_shows_friendly_error_and_keeps_reservation(): void
    {
        $this->providerDown = true;
        $order = $this->identifiedOrder([5]);

        $this->post(route('orders.pay', $order->public_code))->assertSessionHasErrors('order');

        $this->assertSame(0, Payment::count());
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_payment_requires_identified_and_valid_order(): void
    {
        $order = app(ReserveNumbers::class)->reserve($this->promotion, [6], (string) Str::uuid())['order'];
        $this->withSession(['orders.access' => [$order->public_code]]);

        $this->post(route('orders.pay', $order->public_code))->assertSessionHasErrors('order');
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_wrong_or_missing_token(): void
    {
        $this->webhook('evt_1', 'PAYMENT_RECEIVED', 'pay_1', 'errado')->assertUnauthorized();

        config(['services.asaas.webhook_token' => '']);
        $this->webhook('evt_1', 'PAYMENT_RECEIVED', 'pay_1', '')->assertUnauthorized();

        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_webhook_confirms_order_using_the_provider_as_source_of_truth(): void
    {
        $order = $this->identifiedOrder();
        $payment = app(StartPayment::class)($order);

        // O aviso diz "RECEIVED", mas o Asaas ainda diz PENDING: nada é confirmado.
        $this->webhook('evt_1', 'PAYMENT_RECEIVED', $payment->provider_payment_id)->assertOk();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);

        // Agora o Asaas confirma.
        $this->remote[$payment->provider_payment_id]['status'] = 'RECEIVED';
        $this->remote[$payment->provider_payment_id]['paymentDate'] = now()->toDateString();
        $this->webhook('evt_2', 'PAYMENT_RECEIVED', $payment->provider_payment_id)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{16}$/', $order->receipt_code);
        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertSame(3, OrderItem::where('order_id', $order->id)->where('active', 1)->count());
    }

    public function test_repeated_and_out_of_order_webhooks_do_not_change_the_result(): void
    {
        $order = $this->identifiedOrder();
        $payment = app(StartPayment::class)($order);
        $this->remote[$payment->provider_payment_id]['status'] = 'CONFIRMED';

        $this->webhook('evt_9', 'PAYMENT_CONFIRMED', $payment->provider_payment_id)->assertOk();
        $receipt = $order->fresh()->receipt_code;

        $this->webhook('evt_9', 'PAYMENT_CONFIRMED', $payment->provider_payment_id)->assertOk()->assertJson(['message' => 'Aviso já recebido.']);
        $this->assertSame(1, PaymentEvent::where('provider_event_id', 'evt_9')->count());

        // Aviso atrasado de "pendente" depois da confirmação: ignorado.
        $this->remote[$payment->provider_payment_id]['status'] = 'PENDING';
        $this->webhook('evt_0', 'PAYMENT_CREATED', $payment->provider_payment_id)->assertOk();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame($receipt, $order->fresh()->receipt_code);
    }

    public function test_amount_mismatch_sends_order_to_review(): void
    {
        $order = $this->identifiedOrder();
        $payment = app(StartPayment::class)($order);
        $this->remote[$payment->provider_payment_id] = [...$this->remote[$payment->provider_payment_id], 'status' => 'RECEIVED', 'value' => 1.0];

        $this->webhook('evt_1', 'PAYMENT_RECEIVED', $payment->provider_payment_id);

        $this->assertSame(OrderStatus::NeedsReview, $order->fresh()->status);
        $this->assertStringContainsString('valor', $order->fresh()->review_reason);
    }

    public function test_card_in_risk_analysis_holds_the_reservation_until_the_provider_decides(): void
    {
        $order = $this->identifiedOrder([8], PaymentMethod::CreditCard);
        $payment = app(StartPayment::class)($order);

        $this->setRemote($payment->provider_payment_id, ['status' => 'AWAITING_RISK_ANALYSIS']);
        $this->webhook('evt_1', 'PAYMENT_AWAITING_RISK_ANALYSIS', $payment->provider_payment_id);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);

        // Análise demorou mais que a reserva: os números continuam presos ao pedido.
        $this->travel(1)->hours();
        app(ExpireOrders::class)->expireAllDue();
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);

        $this->setRemote($payment->provider_payment_id, ['status' => 'PENDING']);
        $this->webhook('evt_2', 'PAYMENT_REPROVED_BY_RISK_ANALYSIS', $payment->provider_payment_id);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_refused_card_marks_payment_failed_and_keeps_order_waiting(): void
    {
        $order = $this->identifiedOrder([7], PaymentMethod::CreditCard);
        $payment = app(StartPayment::class)($order);

        $this->webhook('evt_1', 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', $payment->provider_payment_id);

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
        $this->get(route('orders.show', $order->public_code))->assertSee('O pagamento não foi aprovado');
    }

    public function test_late_payment_with_numbers_still_free_is_confirmed(): void
    {
        $order = $this->identifiedOrder([8, 9]);
        $payment = app(StartPayment::class)($order);
        $order->forceFill(['reserved_until' => now()->subMinute()])->save();
        app(ExpireOrders::class)->expireAllDue();
        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);

        $this->remote[$payment->provider_payment_id]['status'] = 'RECEIVED';
        $this->webhook('evt_1', 'PAYMENT_RECEIVED', $payment->provider_payment_id);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->where('active', 1)->count());
    }

    public function test_late_payment_never_takes_numbers_from_another_buyer(): void
    {
        $order = $this->identifiedOrder([10, 11]);
        $payment = app(StartPayment::class)($order);
        $order->forceFill(['reserved_until' => now()->subMinute()])->save();

        $other = app(ReserveNumbers::class)->reserve($this->promotion, [11], (string) Str::uuid())['order'];

        $this->remote[$payment->provider_payment_id]['status'] = 'RECEIVED';
        $this->webhook('evt_1', 'PAYMENT_RECEIVED', $payment->provider_payment_id);

        $this->assertSame(OrderStatus::NeedsReview, $order->fresh()->status);
        $this->assertSame([$other->id], OrderItem::where('number', 11)->where('active', 1)->pluck('order_id')->all());
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->where('active', 1)->count());
    }

    public function test_late_payment_after_the_promotion_closed_goes_to_review(): void
    {
        $order = $this->identifiedOrder([12]);
        $payment = app(StartPayment::class)($order);
        $order->forceFill(['reserved_until' => now()->subMinute()])->save();
        app(ExpireOrders::class)->expireAllDue();
        app(PromotionLifecycle::class)->close($this->promotion);

        $this->remote[$payment->provider_payment_id]['status'] = 'RECEIVED';
        $this->webhook('evt_1', 'PAYMENT_RECEIVED', $payment->provider_payment_id);

        // Reativar o número agora seria vender depois do encerramento.
        $this->assertSame(OrderStatus::NeedsReview, $order->fresh()->status);
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->where('active', 1)->count());
    }

    public function test_no_new_charge_while_the_promotion_is_paused_or_closed(): void
    {
        $order = $this->identifiedOrder([15]);
        $lifecycle = app(PromotionLifecycle::class);

        $lifecycle->pause($this->promotion);
        $this->get(route('orders.show', $order->public_code))->assertSee('não aceita novos pagamentos')->assertDontSee('Gerar Pix');
        $this->post(route('orders.pay', $order->public_code))->assertSessionHasErrors('order');
        $this->assertSame(0, Payment::count());

        $lifecycle->resume($this->promotion);
        $this->post(route('orders.pay', $order->public_code))->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::count());

        $other = $this->identifiedOrder([16]);
        $lifecycle->close($this->promotion->fresh());
        $this->post(route('orders.pay', $other->public_code))->assertSessionHasErrors('order');
        $this->assertSame(1, Payment::count());
    }

    public function test_cancelling_the_promotion_cancels_open_reservations(): void
    {
        $order = $this->identifiedOrder([13]);

        app(PromotionLifecycle::class)->cancel($this->promotion);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->where('active', 1)->count());
        $this->post(route('orders.pay', $order->public_code))->assertSessionHasErrors('order');
    }

    public function test_payment_approved_after_the_promotion_was_cancelled_goes_to_review(): void
    {
        $order = $this->identifiedOrder([14], PaymentMethod::CreditCard);
        $payment = app(StartPayment::class)($order);
        $this->setRemote($payment->provider_payment_id, ['status' => 'AWAITING_RISK_ANALYSIS']);
        $this->webhook('evt_1', 'PAYMENT_AWAITING_RISK_ANALYSIS', $payment->provider_payment_id);

        app(PromotionLifecycle::class)->cancel($this->promotion);
        $this->setRemote($payment->provider_payment_id, ['status' => 'CONFIRMED']);
        $this->webhook('evt_2', 'PAYMENT_CONFIRMED', $payment->provider_payment_id);

        $this->assertSame(OrderStatus::NeedsReview, $order->fresh()->status);
    }

    public function test_reconciliation_confirms_without_webhook_and_cancels_charges_of_expired_orders(): void
    {
        $paid = $this->identifiedOrder([12]);
        $paidPayment = app(StartPayment::class)($paid);
        $this->remote[$paidPayment->provider_payment_id]['status'] = 'RECEIVED';

        $expired = $this->identifiedOrder([13]);
        $expiredPayment = app(StartPayment::class)($expired);
        $expired->forceFill(['reserved_until' => now()->subMinute()])->save();
        app(ExpireOrders::class)->expireAllDue();

        Payment::query()->update(['last_checked_at' => now()->subMinutes(5)]);
        $this->artisan('payments:sync')->assertSuccessful();

        $this->assertSame(OrderStatus::Confirmed, $paid->fresh()->status);
        $this->assertSame(PaymentStatus::Cancelled, $expiredPayment->fresh()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/payments/'.$expiredPayment->provider_payment_id));
    }

    public function test_order_page_status_endpoint_checks_the_provider(): void
    {
        $order = $this->identifiedOrder([14]);
        $payment = app(StartPayment::class)($order);
        $payment->forceFill(['last_checked_at' => now()->subMinute()])->save();
        $this->remote[$payment->provider_payment_id]['status'] = 'RECEIVED';

        $this->getJson(route('orders.status', $order->public_code))
            ->assertOk()->assertJson(['order' => 'confirmed', 'payment' => 'confirmed']);
    }

    public function test_webhook_recovers_a_charge_that_was_never_saved_locally(): void
    {
        $order = $this->identifiedOrder([15]);
        $this->remote['pay_orfa'] = [
            'id' => 'pay_orfa', 'status' => 'RECEIVED', 'value' => 5.0, 'externalReference' => $order->public_code,
            'billingType' => 'PIX', 'dueDate' => now()->toDateString(),
        ];

        $this->webhook('evt_1', 'PAYMENT_RECEIVED', 'pay_orfa')->assertOk();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame('pay_orfa', $order->payments()->sole()->provider_payment_id);
    }

    public function test_admin_refund_waits_for_provider_and_keeps_numbers_held(): void
    {
        $order = $this->identifiedOrder([16]);
        $payment = app(StartPayment::class)($order);
        $this->remote[$payment->provider_payment_id]['status'] = 'RECEIVED';
        $this->webhook('evt_1', 'PAYMENT_RECEIVED', $payment->provider_payment_id);

        $this->actingAs(User::factory()->admin()->create());
        app(RefundOrder::class)($order->fresh());

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->where('active', 1)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.refund_requested', 'subject_id' => $order->id]);
    }

    public function test_cancelling_an_order_cancels_the_open_charge(): void
    {
        $order = $this->identifiedOrder([17]);
        $payment = app(StartPayment::class)($order);

        $this->post(route('orders.cancel', $order->public_code));

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/payments/'.$payment->provider_payment_id));
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_admin_order_pages(): void
    {
        $order = $this->identifiedOrder([18]);

        $this->actingAs(User::factory()->create())->get('/admin/pedidos')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/pedidos')->assertOk()->assertSee($order->public_code);
        $this->get("/admin/pedidos/{$order->id}")->assertOk()->assertSee('***.982.247-**')->assertDontSee('52998224725');
    }
}
