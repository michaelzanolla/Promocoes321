<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PromotionStatus;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use App\Services\PromotionLifecycle;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private PromotionLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycle = app(PromotionLifecycle::class);
        $this->actingAs(User::factory()->admin()->create());
    }

    private function readyDraft(array $overrides = []): Promotion
    {
        return Promotion::factory()->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(10),
            'rules' => 'Regras.',
            'draw_info' => 'Loteria Federal.',
            'legal_reference' => 'Parecer 123',
            ...$overrides,
        ]);
    }

    public function test_incomplete_draft_can_not_be_published(): void
    {
        $draft = Promotion::factory()->create();

        $blockers = $this->lifecycle->publishBlockers($draft);
        $this->assertContains('Defina a data de início e a de encerramento.', $blockers);
        $this->assertContains('Escreva as regras de participação.', $blockers);

        $this->expectException(DomainException::class);
        $this->lifecycle->publish($draft);
    }

    public function test_complete_draft_is_published_and_audited(): void
    {
        $draft = $this->readyDraft();

        $this->lifecycle->publish($draft);

        $this->assertSame(PromotionStatus::Published, $draft->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'promotion.published', 'subject_id' => $draft->id]);
    }

    public function test_production_requires_legal_reference_and_blocks_demo(): void
    {
        $this->app['env'] = 'production';

        $blockers = $this->lifecycle->publishBlockers($this->readyDraft(['legal_reference' => null, 'is_demo' => true]));

        $this->assertContains('Promoções fictícias não podem ser publicadas em produção.', $blockers);
        $this->assertContains('Preencha a referência legal (autorização ou parecer jurídico) antes de publicar.', $blockers);
        $this->assertSame([], $this->lifecycle->publishBlockers($this->readyDraft()));
    }

    public function test_pause_resume_and_close_follow_allowed_transitions(): void
    {
        $promotion = Promotion::factory()->open()->create();

        $this->lifecycle->pause($promotion);
        $this->assertSame(PromotionStatus::Paused, $promotion->status);

        $this->lifecycle->resume($promotion);
        $this->assertSame(PromotionStatus::Published, $promotion->status);

        $this->lifecycle->close($promotion);
        $this->assertSame(PromotionStatus::Closed, $promotion->status);

        $this->expectException(DomainException::class);
        $this->lifecycle->resume($promotion);
    }

    public function test_result_requires_closed_promotion_without_pending_orders(): void
    {
        $promotion = Promotion::factory()->open()->create(['total_numbers' => 100]);

        try {
            $this->lifecycle->recordResult($promotion, 10, null, null);
            $this->fail('Deveria exigir promoção encerrada.');
        } catch (DomainException) {
        }

        $this->lifecycle->close($promotion);
        $pending = Order::factory()->create(['promotion_id' => $promotion->id, 'status' => OrderStatus::AwaitingPayment]);

        try {
            $this->lifecycle->recordResult($promotion, 10, null, null);
            $this->fail('Deveria bloquear com pedido pendente.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('1 pedido', $e->getMessage());
        }

        $pending->forceFill(['status' => OrderStatus::Expired])->save();

        try {
            $this->lifecycle->recordResult($promotion, 500, null, null);
            $this->fail('Deveria recusar número fora da faixa.');
        } catch (DomainException) {
        }

        $this->lifecycle->recordResult($promotion, 42, 'https://exemplo.test/video', 'Ao vivo.');

        $promotion->refresh();
        $this->assertSame(PromotionStatus::Drawn, $promotion->status);
        $this->assertSame(42, $promotion->result_number);
        $this->assertNotNull($promotion->result_published_at);
    }

    public function test_promotion_whose_end_date_passed_counts_as_closed_for_the_result(): void
    {
        $promotion = Promotion::factory()->create([
            'status' => PromotionStatus::Published, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subMinute(),
        ]);

        $this->lifecycle->recordResult($promotion, 1, null, null);

        $this->assertSame(PromotionStatus::Drawn, $promotion->fresh()->status);
    }

    public function test_duplicate_creates_a_clean_draft(): void
    {
        $original = Promotion::factory()->create(['status' => PromotionStatus::Drawn, 'result_number' => 7, 'slug' => 'bicicleta']);
        $original->images()->create(['path' => 'promocoes/a.jpg', 'position' => 0]);

        $copy = $this->lifecycle->duplicate($original);

        $this->assertSame(PromotionStatus::Draft, $copy->status);
        $this->assertNull($copy->result_number);
        $this->assertSame('bicicleta-copia', $copy->slug);
        $this->assertSame(1, $copy->images()->count());
        $this->assertSame('bicicleta-copia-2', $this->lifecycle->duplicate($original)->slug);
    }

    public function test_price_and_numbering_are_locked_after_first_order(): void
    {
        $promotion = Promotion::factory()->open()->create();
        $promotion->update(['unit_price_cents' => 900]); // sem pedidos: pode

        Order::factory()->create(['promotion_id' => $promotion->id]);

        $promotion->update(['title' => 'Título novo']); // conteúdo continua editável
        $this->assertSame('Título novo', $promotion->fresh()->title);

        $this->expectException(DomainException::class);
        $promotion->update(['total_numbers' => 50]);
    }

    public function test_only_drafts_without_orders_can_be_deleted(): void
    {
        $admin = auth()->user();
        $draft = Promotion::factory()->create();
        $withOrder = Promotion::factory()->create();
        Order::factory()->create(['promotion_id' => $withOrder->id]);

        $this->assertTrue($admin->can('delete', $draft));
        $this->assertFalse($admin->can('delete', $withOrder));
        $this->assertFalse($admin->can('delete', Promotion::factory()->open()->create()));
    }

    public function test_drawn_and_cancelled_promotions_are_frozen(): void
    {
        $admin = auth()->user();

        $this->assertFalse($admin->can('update', Promotion::factory()->create(['status' => PromotionStatus::Drawn])));
        $this->assertFalse($admin->can('update', Promotion::factory()->create(['status' => PromotionStatus::Cancelled])));
        $this->assertTrue($admin->can('update', Promotion::factory()->open()->create()));
    }
}
