<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\NumbersUnavailable;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\User;
use App\Services\AdminMetrics;
use App\Services\PromotionLifecycle;
use App\Services\ReserveNumbers;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ação que começou fora do site: numeração 0001 a 1000, site vende só 0501 a 1000,
 * e o administrador marca números vendidos fora.
 */
class OfflineSalesTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $promotion;

    private ReserveNumbers $reserve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reserve = app(ReserveNumbers::class);
        $this->promotion = Promotion::factory()->open()->create([
            'slug' => 'acao-iniciada-fora', 'series_start' => 1, 'series_end' => 1000, 'number_start' => 501, 'total_numbers' => 500,
        ]);
    }

    public function test_admin_creates_a_promotion_selling_only_part_of_the_numbers(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $base = ['title' => 'Rifa da festa', 'slug' => 'rifa-da-festa', 'unit_price_cents' => '5', 'min_per_order' => 1,
            'reservation_minutes' => 15, 'payment_methods' => ['pix'], 'series_start' => 1, 'series_end' => 1000, 'online_scope' => 'range'];

        Livewire::test(CreatePromotion::class)
            ->fillForm([...$base, 'number_start' => 501, 'online_end' => 1200])
            ->call('create')
            ->assertHasFormErrors(['online_end']);

        Livewire::test(CreatePromotion::class)
            ->fillForm([...$base, 'number_start' => 501, 'online_end' => 1000])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Promotion::where('slug', 'rifa-da-festa')->sole();
        $this->assertSame([1, 1000, 501, 500], [$created->series_start, $created->series_end, $created->number_start, $created->total_numbers]);
        $this->assertSame('0501', $created->formatNumber(501));

        // Ao abrir para editar, a tela mostra a mesma faixa.
        Livewire::test(EditPromotion::class, ['record' => $created->getRouteKey()])
            ->assertFormSet(['online_scope' => 'range', 'number_start' => 501, 'online_end' => 1000, 'series_end' => 1000]);
    }

    public function test_site_only_shows_and_sells_its_range(): void
    {
        $this->get(route('promotions.show', 'acao-iniciada-fora'))->assertSee('0501')->assertSee('1000');

        $this->expectException(DomainException::class);
        $this->reserve->reserve($this->promotion, [100], (string) Str::uuid());
    }

    public function test_numbers_sold_offline_become_unavailable_on_the_site(): void
    {
        $marked = $this->reserve->markSoldOffline($this->promotion, ReserveNumbers::parseList('507, 512, 600 - 602'), 'talão do João');

        $this->assertTrue($marked->isOffline());
        $this->assertSame(5, $marked->quantity);
        $this->assertSame(0, $marked->total_cents);

        $status = $this->getJson(route('numbers.status', 'acao-iniciada-fora'))->json();
        $this->assertSame([507, 512, 600, 601, 602], $status['sold']);
        $this->assertSame(5, Promotion::withNumberCounts()->find($this->promotion->id)->sold_count);

        try {
            $this->reserve->reserve($this->promotion, [512], (string) Str::uuid());
            $this->fail('Número vendido fora do site foi reservado no site.');
        } catch (NumbersUnavailable $e) {
            $this->assertSame([512], $e->numbers);
        }

        // Marcação não é pedido do site: não aparece nos números de pedidos do painel.
        $this->assertSame(0, app(AdminMetrics::class)->orders()['confirmed']);
    }

    public function test_marking_a_number_already_taken_on_the_site_marks_nothing(): void
    {
        $this->reserve->reserve($this->promotion, [520], (string) Str::uuid());

        try {
            $this->reserve->markSoldOffline($this->promotion, [519, 520, 521]);
            $this->fail('Marcou um número já reservado no site.');
        } catch (NumbersUnavailable $e) {
            $this->assertSame([520], $e->numbers);
        }

        $this->assertSame(0, Order::where('source', Order::SOURCE_OFFLINE)->count());
        $this->assertSame(0, OrderItem::whereIn('number', [519, 521])->count());
    }

    public function test_marking_outside_the_site_range_or_after_closing_is_refused(): void
    {
        try {
            $this->reserve->markSoldOffline($this->promotion, [10]);
            $this->fail('Marcou número fora da faixa do site.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('fora da faixa vendida no site', $e->getMessage());
        }

        $marked = $this->reserve->markSoldOffline($this->promotion, [700]);
        app(PromotionLifecycle::class)->close($this->promotion);

        $this->expectException(DomainException::class);
        $this->reserve->unmarkOffline($marked);
    }

    public function test_unmarking_puts_the_numbers_back_on_sale(): void
    {
        $marked = $this->reserve->markSoldOffline($this->promotion, [800, 801]);

        $this->reserve->unmarkOffline($marked);

        $this->assertSame(OrderStatus::Cancelled, $marked->fresh()->status);
        $order = $this->reserve->reserve($this->promotion, [800, 801], (string) Str::uuid())['order'];
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
    }

    public function test_number_list_parsing(): void
    {
        $this->assertSame([1, 5, 6, 7, 9], ReserveNumbers::parseList(' 9; 1, 5-7 , 5 '));

        foreach (['', 'abc', '10-5', '1-20000'] as $invalid) {
            try {
                ReserveNumbers::parseList($invalid);
                $this->fail("Aceitou \"{$invalid}\".");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_draw_accepts_numbers_sold_outside_the_site(): void
    {
        $this->reserve->markSoldOffline($this->promotion, [900]);
        $lifecycle = app(PromotionLifecycle::class);
        $lifecycle->close($this->promotion);

        $lifecycle->recordResult($this->promotion, 42, null, null); // fora da faixa do site, dentro da ação

        $this->get(route('promotions.show', 'acao-iniciada-fora'))
            ->assertSee('0042')->assertSee('Este número foi vendido fora do site.');

        $this->expectException(DomainException::class);
        $other = Promotion::factory()->create(['status' => 'closed', 'series_start' => 1, 'series_end' => 1000, 'number_start' => 501, 'total_numbers' => 500]);
        $lifecycle->recordResult($other, 1001, null, null);
    }

    public function test_admin_marks_and_unmarks_through_the_panel(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditPromotion::class, ['record' => $this->promotion->getRouteKey()])
            ->callAction('offlineSales', ['numbers' => '507, 600-602', 'note' => 'vendidos na feira'])
            ->assertNotified('4 números marcados como vendidos fora do site.');

        $marked = Order::where('source', Order::SOURCE_OFFLINE)->sole();
        $this->assertSame('vendidos na feira', $marked->internal_note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.offline_marked', 'subject_id' => $marked->id]);

        Livewire::test(EditPromotion::class, ['record' => $this->promotion->getRouteKey()])
            ->callAction('offlineSales', ['numbers' => '601, 650'])
            ->assertNotified('Nada foi marcado');
        $this->assertSame(1, Order::where('source', Order::SOURCE_OFFLINE)->count());

        Livewire::test(ViewOrder::class, ['record' => $marked->getRouteKey()])
            ->assertSee('vendidos na feira')
            ->assertActionHidden('refund')
            ->callAction('unmarkOffline');
        $this->assertSame(OrderStatus::Cancelled, $marked->fresh()->status);
    }
}
