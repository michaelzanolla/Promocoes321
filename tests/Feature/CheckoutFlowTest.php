<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\User;
use App\Rules\Cpf;
use App\Services\ReserveNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $promotion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->promotion = Promotion::factory()->open()->create(['total_numbers' => 50, 'unit_price_cents' => 500, 'payment_methods' => ['pix']]);
    }

    private function reserveViaHttp(array $numbers): Order
    {
        $this->postJson(route('numbers.reserve', $this->promotion->slug), [
            'numbers' => $numbers, 'checkout_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonStructure(['redirect']);

        return Order::latest('id')->firstOrFail();
    }

    public function test_selection_page_and_status_endpoint(): void
    {
        $this->get(route('promotions.show', $this->promotion->slug))->assertSee('Escolher números');
        $this->get(route('numbers.show', $this->promotion->slug))->assertOk()->assertSee('Escolha seus números');

        $order = app(ReserveNumbers::class)->reserve($this->promotion, [3, 4], (string) Str::uuid())['order'];
        $order->forceFill(['status' => OrderStatus::Confirmed])->save();
        app(ReserveNumbers::class)->reserve($this->promotion, [9], (string) Str::uuid());

        $this->getJson(route('numbers.status', $this->promotion->slug))
            ->assertOk()
            ->assertJson(['sold' => [3, 4], 'reserved' => [9], 'open' => true]);
    }

    public function test_selection_page_redirects_when_promotion_is_not_open(): void
    {
        $closed = Promotion::factory()->open()->create(['ends_at' => now()->subMinute()]);

        $this->get(route('numbers.show', $closed->slug))->assertRedirect(route('promotions.show', $closed->slug));
    }

    public function test_guest_reserves_identifies_and_sees_the_order(): void
    {
        $order = $this->reserveViaHttp([1, 2, 3]);

        $this->get(route('orders.show', $order->public_code))
            ->assertOk()->assertSee($order->public_code)->assertSee('R$ 15,00')->assertSee('Seus dados');

        $this->put(route('orders.identify', $order->public_code), [
            'buyer_name' => 'Maria da Silva',
            'buyer_email' => 'Maria@Example.com',
            'buyer_cpf' => '529.982.247-25',
            'payment_method' => 'pix',
            'accept_terms' => '1',
        ])->assertRedirect(route('orders.show', $order->public_code));

        $order->refresh();
        $this->assertTrue($order->isIdentified());
        $this->assertSame('maria@example.com', $order->buyer_email);
        $this->assertSame('52998224725', $order->buyer_cpf);
        $this->assertSame(PaymentMethod::Pix, $order->payment_method);
        $this->assertNotNull($order->terms_accepted_at);
    }

    public function test_identification_validates_cpf_terms_and_payment_method(): void
    {
        $order = $this->reserveViaHttp([5]);

        $this->put(route('orders.identify', $order->public_code), [
            'buyer_name' => 'Maria', 'buyer_email' => 'maria@example.com',
            'buyer_cpf' => '111.111.111-11', 'payment_method' => 'credit_card',
        ])->assertSessionHasErrors(['buyer_cpf', 'payment_method', 'accept_terms']);

        $this->assertFalse($order->fresh()->isIdentified());
    }

    public function test_conflict_returns_409_with_the_taken_numbers(): void
    {
        $this->reserveViaHttp([10]);

        $this->postJson(route('numbers.reserve', $this->promotion->slug), [
            'numbers' => [10, 11], 'checkout_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJson(['conflicts' => [10]]);

        $this->assertSame(0, OrderItem::where('number', 11)->count());
    }

    public function test_other_people_can_not_see_the_order(): void
    {
        $order = $this->reserveViaHttp([20]);

        // Outra sessão (navegador diferente), logada ou não.
        $this->flushSession();
        $this->get(route('orders.show', $order->public_code))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('orders.show', $order->public_code))->assertNotFound();
        $this->put(route('orders.identify', $order->public_code), [])->assertNotFound();
        $this->post(route('orders.cancel', $order->public_code))->assertNotFound();
    }

    public function test_secret_link_grants_access_and_wrong_token_does_not(): void
    {
        ['order' => $order, 'token' => $token] = app(ReserveNumbers::class)->reserve($this->promotion, [25], (string) Str::uuid());

        $this->get(route('orders.show', [$order->public_code, 't' => 'errado']))->assertNotFound();
        $this->get(route('orders.show', [$order->public_code, 't' => $token]))->assertRedirect(route('orders.show', $order->public_code));
        $this->get(route('orders.show', $order->public_code))->assertOk();
    }

    public function test_logged_in_owner_and_admin_can_see_the_order(): void
    {
        $owner = User::factory()->create();
        $order = app(ReserveNumbers::class)->reserve($this->promotion, [26], (string) Str::uuid(), $owner)['order'];

        $this->actingAs($owner)->get(route('orders.show', $order->public_code))->assertOk()->assertSee($owner->name);
        $this->actingAs(User::factory()->admin()->create())->get(route('orders.show', $order->public_code))->assertOk();
    }

    public function test_buyer_can_cancel_and_numbers_are_released(): void
    {
        $order = $this->reserveViaHttp([30, 31]);

        $this->post(route('orders.cancel', $order->public_code))->assertRedirect(route('orders.show', $order->public_code));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->reserveViaHttp([30]);
    }

    public function test_expired_order_can_not_be_identified(): void
    {
        $order = $this->reserveViaHttp([35]);
        $order->forceFill(['reserved_until' => now()->subMinute()])->save();

        $this->get(route('orders.show', $order->public_code))->assertOk()->assertDontSee('Confirmar dados');
        $this->put(route('orders.identify', $order->public_code), [
            'buyer_name' => 'Maria', 'buyer_email' => 'maria@example.com', 'buyer_cpf' => '52998224725',
            'payment_method' => 'pix', 'accept_terms' => '1',
        ])->assertSessionHasErrors('order');
    }

    public function test_reservations_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('numbers.reserve', $this->promotion->slug), ['numbers' => [999], 'checkout_key' => (string) Str::uuid()]);
        }

        $this->postJson(route('numbers.reserve', $this->promotion->slug), ['numbers' => [1], 'checkout_key' => (string) Str::uuid()])
            ->assertStatus(429);
    }

    public function test_cpf_rule(): void
    {
        $this->assertTrue(Cpf::isValid('529.982.247-25'));
        $this->assertFalse(Cpf::isValid('529.982.247-24'));
        $this->assertFalse(Cpf::isValid('000.000.000-00'));
        $this->assertFalse(Cpf::isValid('123'));
    }
}
