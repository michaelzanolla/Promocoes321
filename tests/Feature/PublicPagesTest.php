<?php

namespace Tests\Feature;

use App\Enums\PromotionStatus;
use App\Http\Controllers\PageController;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_lists_open_promotions_from_the_database(): void
    {
        Promotion::factory()->open()->create(['title' => 'Bicicleta aro 29']);
        Promotion::factory()->create(['title' => 'Rascunho secreto']);
        Promotion::factory()->create(['title' => 'Promoção cancelada', 'status' => PromotionStatus::Cancelled]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Bicicleta aro 29')
            ->assertDontSee('Rascunho secreto')
            ->assertDontSee('Promoção cancelada');
    }

    public function test_home_shows_empty_state_without_promotions(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Nenhuma promoção aberta no momento.');
    }

    public function test_search_and_filters(): void
    {
        Promotion::factory()->open()->create(['title' => 'Bicicleta aro 29', 'category' => 'Esportes']);
        Promotion::factory()->open()->create(['title' => 'Notebook', 'category' => 'Eletrônicos']);
        Promotion::factory()->create(['title' => 'Já encerrada', 'status' => PromotionStatus::Closed]);

        Livewire::test('promotion-list')
            ->assertSee('Bicicleta aro 29')->assertSee('Notebook')->assertDontSee('Já encerrada')
            ->set('search', 'bici')
            ->assertSee('Bicicleta aro 29')->assertDontSee('Notebook')
            ->set('search', '')->set('category', 'Eletrônicos')
            ->assertSee('Notebook')->assertDontSee('Bicicleta aro 29')
            ->set('category', '')->set('phase', 'closed')
            ->assertSee('Já encerrada')->assertDontSee('Notebook')
            ->set('search', 'nada parecido')
            ->assertSee('Nenhuma promoção encontrada com esses filtros.')
            ->call('clearFilters')
            ->assertSee('Notebook');
    }

    public function test_demo_promotions_are_hidden_in_production(): void
    {
        Promotion::factory()->open()->create(['title' => '[FICTÍCIA] Teste', 'is_demo' => true]);

        $this->assertSame(1, Promotion::publiclyVisible()->count());

        $this->app['env'] = 'production';
        $this->assertSame(0, Promotion::publiclyVisible()->count());
    }

    public function test_promotion_page_shows_details(): void
    {
        $promotion = Promotion::factory()->open()->create([
            'title' => 'Bicicleta aro 29',
            'unit_price_cents' => 1250,
            'rules' => 'Uma regra **importante**.',
        ]);

        $this->get(route('promotions.show', $promotion->slug))
            ->assertOk()
            ->assertSee('Bicicleta aro 29')
            ->assertSee('R$ 12,50')
            ->assertSee('<strong>importante</strong>', escape: false);
    }

    public function test_promotion_text_can_not_inject_html(): void
    {
        $promotion = Promotion::factory()->open()->create(['description' => '<script>alert(1)</script> texto']);

        $this->get(route('promotions.show', $promotion->slug))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', escape: false);
    }

    public function test_drafts_are_not_reachable(): void
    {
        $draft = Promotion::factory()->create();

        $this->get(route('promotions.show', $draft->slug))->assertNotFound();
    }

    public function test_institutional_pages_render(): void
    {
        foreach (array_keys(PageController::PAGES) as $page) {
            $this->get(route('pages.show', $page))->assertOk();
        }

        $this->get('/paginas/inexistente')->assertNotFound();
    }
}
