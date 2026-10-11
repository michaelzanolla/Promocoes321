<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Mail\OrderMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\User;
use App\Payments\Charge;
use App\Payments\SyncPayment;
use App\Services\ReserveNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptsAndAccountTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $promotion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Mail::fake();
        $this->promotion = Promotion::factory()->open()->create(['title' => 'Bicicleta aro 29', 'unit_price_cents' => 500, 'total_numbers' => 100]);
    }

    /** Pedido identificado e pago (confirmação aplicada como o provedor faria). */
    private function paidOrder(array $numbers = [7, 42], ?User $user = null, string $email = 'maria@example.com'): Order
    {
        $order = app(ReserveNumbers::class)->reserve($this->promotion, $numbers, (string) Str::uuid(), $user)['order'];
        $order->forceFill([
            'buyer_name' => 'Maria Aparecida da Silva', 'buyer_email' => $email, 'buyer_cpf' => '52998224725',
            'buyer_phone' => '11999998888', 'payment_method' => PaymentMethod::Pix, 'terms_accepted_at' => now(),
        ])->save();

        $payment = Payment::forceCreate([
            'order_id' => $order->id, 'provider' => 'asaas', 'provider_payment_id' => 'pay_'.$order->id,
            'method' => PaymentMethod::Pix, 'amount_cents' => $order->total_cents, 'status' => PaymentStatus::Pending,
        ]);
        app(SyncPayment::class)->apply($payment, new Charge(
            id: 'pay_'.$order->id, status: PaymentStatus::Confirmed, providerStatus: 'RECEIVED',
            amountCents: $order->total_cents, externalReference: $order->public_code,
        ));

        return $order->fresh();
    }

    public function test_public_verification_shows_receipt_without_personal_data(): void
    {
        $order = $this->paidOrder();

        $this->get(route('receipts.verify', $order->receipt_code))
            ->assertOk()
            ->assertSee('Válido: pagamento confirmado.')
            ->assertSee('Bicicleta aro 29')
            ->assertSee('Maria S.')
            ->assertSee('007')->assertSee('042')
            ->assertSee('R$ 10,00')
            ->assertSee($order->receipt_code)
            ->assertSee('data:image/svg+xml;base64', escape: false)
            ->assertDontSee('Aparecida')
            ->assertDontSee('maria@example.com')
            ->assertDontSee('52998224725')
            ->assertDontSee('529.982.247-25')
            ->assertDontSee('11999998888');
    }

    public function test_verification_always_reflects_the_database(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['status' => OrderStatus::Refunded])->save();

        $this->get(route('receipts.verify', $order->receipt_code))->assertOk()->assertSee('Não é mais válido');
        $this->get(route('receipts.verify', 'NAOEXISTE1234567'))->assertNotFound();
    }

    public function test_buyer_sees_full_receipt_and_downloads_pdf_strangers_do_not(): void
    {
        $order = $this->paidOrder();
        $this->withSession(['orders.access' => [$order->public_code]]);

        $this->get(route('orders.receipt', $order->public_code))->assertOk()->assertSee('Maria Aparecida da Silva')->assertSee('Baixar PDF');

        $pdf = $this->get(route('orders.receipt.pdf', $order->public_code));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->flushSession();
        $this->get(route('orders.receipt', $order->public_code))->assertNotFound();
        $this->get(route('orders.receipt.pdf', $order->public_code))->assertNotFound();
    }

    public function test_unpaid_orders_have_no_receipt(): void
    {
        $order = app(ReserveNumbers::class)->reserve($this->promotion, [1], (string) Str::uuid())['order'];
        $this->withSession(['orders.access' => [$order->public_code]]);

        $this->get(route('orders.receipt', $order->public_code))->assertNotFound();
    }

    public function test_emails_on_identification_and_confirmation_carry_the_secret_link(): void
    {
        $order = app(ReserveNumbers::class)->reserve($this->promotion, [3], (string) Str::uuid())['order'];
        $this->withSession(['orders.access' => [$order->public_code]]);

        $this->put(route('orders.identify', $order->public_code), [
            'buyer_name' => 'Maria da Silva', 'buyer_email' => 'maria@example.com', 'buyer_cpf' => '52998224725',
            'payment_method' => 'pix', 'accept_terms' => '1',
        ]);

        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->kind === OrderMail::RESERVED && $m->hasTo('maria@example.com'));

        $sent = Mail::sent(OrderMail::class)->first();
        $this->assertStringContainsString('t='.$order->fresh()->access_token, $sent->render());

        $this->paidOrder([4]);
        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->kind === OrderMail::CONFIRMED);
    }

    public function test_secret_link_from_email_opens_the_order_in_any_browser(): void
    {
        $order = $this->paidOrder();

        $this->get($order->secretUrl())->assertRedirect(route('orders.show', $order->public_code));
        $this->get(route('orders.show', $order->public_code))->assertOk()->assertSee('Ver comprovante');
    }

    public function test_lookup_sends_link_only_when_code_and_email_match_and_answers_the_same(): void
    {
        $order = $this->paidOrder();

        $wrong = $this->from(route('orders.lookup'))->post(route('orders.lookup.store'), ['code' => $order->public_code, 'email' => 'outra@example.com']);
        Mail::assertNotSent(OrderMail::class, fn (OrderMail $m) => $m->kind === OrderMail::LINK);

        $right = $this->from(route('orders.lookup'))->post(route('orders.lookup.store'), ['code' => strtolower($order->public_code), 'email' => 'MARIA@example.com']);
        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->kind === OrderMail::LINK && $m->hasTo('maria@example.com'));

        $this->assertSame($wrong->getSession()->get('status'), $right->getSession()->get('status'));
    }

    public function test_lookup_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('orders.lookup.store'), ['code' => 'XXXXXXXXXX', 'email' => 'a@example.com']);
        }

        $this->post(route('orders.lookup.store'), ['code' => 'XXXXXXXXXX', 'email' => 'a@example.com'])->assertStatus(429);
    }

    public function test_my_orders_shows_own_and_same_verified_email_orders_only(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $mine = $this->paidOrder([10], $user);
        $asGuest = $this->paidOrder([11], null, 'maria@example.com');
        $someoneElse = $this->paidOrder([12], null, 'joao@example.com');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee($mine->public_code)
            ->assertSee($asGuest->public_code)
            ->assertDontSee($someoneElse->public_code);

        $this->get(route('orders.show', $asGuest->public_code))->assertOk();
        $this->get(route('orders.show', $someoneElse->public_code))->assertNotFound();
    }

    public function test_unverified_account_does_not_get_guest_orders_by_email(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'maria@example.com']);
        $asGuest = $this->paidOrder([20], null, 'maria@example.com');

        $this->assertSame(0, Order::visibleTo($user)->count());
        $this->assertFalse($asGuest->isAccessibleBy($user, app('session.store')));
    }

    public function test_masked_buyer_name(): void
    {
        $order = new Order;

        foreach (['MARIA aparecida da silva' => 'Maria S.', 'João' => 'João', '' => 'Comprador', 'Administrador (demo)' => 'Administrador D.', '()' => 'Comprador'] as $name => $expected) {
            $order->buyer_name = $name;
            $this->assertSame($expected, $order->maskedBuyerName());
        }
    }
}
