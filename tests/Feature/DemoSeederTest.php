<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Enums\PromotionStatus;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); // a ata fictícia do exemplo não vai para a pasta real
    }

    public function test_demo_data_is_clearly_fictitious(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Promotion::count());
        $this->assertSame(0, Promotion::where('is_demo', false)->count());
        $this->assertSame(0, Promotion::where('title', 'not like', '[FICTÍCIA]%')->count());
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_demo_data_has_an_example_of_every_situation(): void
    {
        $this->seed(DatabaseSeeder::class);

        $phases = Promotion::all()->map(fn (Promotion $p) => $p->phase())->unique()->values();
        foreach (PromotionPhase::cases() as $phase) {
            $this->assertContains($phase, $phases, "Falta promoção de exemplo: {$phase->label()}");
        }

        $statuses = Order::pluck('status')->unique();
        foreach (OrderStatus::cases() as $status) {
            $this->assertContains($status, $statuses, "Falta pedido de exemplo: {$status->label()}");
        }

        $drawn = Promotion::where('status', PromotionStatus::Drawn)->sole();
        $this->assertSame(42, $drawn->result_number);
        Storage::disk('public')->assertExists($drawn->result_proof_path);
    }

    /** Cancelar, pagar ou trocar os dados só vale para pedido aguardando pagamento. */
    public function test_buyer_actions_are_refused_in_every_other_situation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests(); // nenhuma cobrança pode chegar a ser criada

        foreach (Order::where('status', '!=', OrderStatus::AwaitingPayment)->get() as $order) {
            $before = $order->only(['status', 'buyer_name', 'buyer_email']);
            $this->withSession(['orders.access' => [$order->public_code]]);

            $this->post(route('orders.cancel', $order->public_code))->assertSessionHasErrors('order');
            $this->post(route('orders.pay', $order->public_code))->assertSessionHasErrors('order');
            $this->put(route('orders.identify', $order->public_code), [
                'buyer_name' => 'Outra Pessoa', 'buyer_email' => 'outra@example.com', 'buyer_cpf' => '529.982.247-25',
                'payment_method' => 'pix', 'accept_terms' => '1',
            ])->assertSessionHasErrors('order');

            $this->assertSame($before, $order->fresh()->only(['status', 'buyer_name', 'buyer_email']), "Pedido {$order->status->label()} foi alterado.");
        }
    }

    /** Todas as telas abrem em todas as situações, para quem pode vê-las. */
    public function test_every_screen_opens_in_every_situation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('role', 'admin')->sole();
        $participant = User::where('email', 'participante@demo.test')->sole();

        $this->actingAs($participant)->get('/dashboard')->assertOk()->assertSee('Reembolsado')->assertSee('Pagamento em processamento');
        $this->get('/admin')->assertForbidden();

        $this->actingAs($admin);
        $this->get('/admin')->assertOk();
        $this->get(route('filament.admin.resources.promocoes.index'))->assertOk();
        $this->get(route('filament.admin.resources.pedidos.index'))->assertOk();

        foreach (Promotion::all() as $promotion) {
            $this->get(route('filament.admin.resources.promocoes.view', $promotion))->assertOk();
            $frozen = in_array($promotion->status, [PromotionStatus::Drawn, PromotionStatus::Cancelled], true);
            $this->get(route('filament.admin.resources.promocoes.edit', $promotion))->assertStatus($frozen ? 403 : 200);
            $this->get("/promocoes/{$promotion->slug}")->assertOk(); // administrador vê até rascunho e cancelada
        }

        // Números presos sem pagamento só são mencionados enquanto há venda.
        $this->get('/promocoes/ficticia-pedidos-em-todas-as-situacoes')->assertSee('ainda sem pagamento confirmado');
        $this->get('/promocoes/ficticia-resultado-publicado-smart-tv')->assertDontSee('ainda sem pagamento confirmado');

        $this->withoutMiddleware(ThrottleRequests::class); // são dezenas de pedidos em sequência
        foreach (Order::all() as $order) {
            $this->get(route('filament.admin.resources.pedidos.view', $order))->assertOk();
            $this->get("/pedido/{$order->public_code}")->assertOk();
            if ($order->receipt_code) {
                $this->get("/comprovante/{$order->receipt_code}")->assertOk();
                $this->get("/pedido/{$order->public_code}/comprovante.pdf")->assertOk();
            }
        }
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->app->make(DatabaseSeeder::class)->run();
    }
}
