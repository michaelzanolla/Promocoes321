<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Enums\PromotionStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionPhaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, Promotion>
     */
    private function onePerPhase(): array
    {
        return [
            'scheduled' => Promotion::factory()->create(['status' => PromotionStatus::Published, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(5)]),
            'open' => Promotion::factory()->open()->create(),
            'expired' => Promotion::factory()->create(['status' => PromotionStatus::Published, 'starts_at' => now()->subDays(5), 'ends_at' => now()->subMinute()]),
            'closed' => Promotion::factory()->create(['status' => PromotionStatus::Closed]),
            'paused' => Promotion::factory()->create(['status' => PromotionStatus::Paused]),
            'drawn' => Promotion::factory()->create(['status' => PromotionStatus::Drawn]),
        ];
    }

    public function test_phase_is_derived_from_status_and_dates(): void
    {
        $p = $this->onePerPhase();

        $this->assertSame(PromotionPhase::Scheduled, $p['scheduled']->phase());
        $this->assertSame(PromotionPhase::Open, $p['open']->phase());
        $this->assertSame(PromotionPhase::Closed, $p['expired']->phase());
        $this->assertSame(PromotionPhase::Closed, $p['closed']->phase());
        $this->assertSame(PromotionPhase::Paused, $p['paused']->phase());
        $this->assertSame(PromotionPhase::Drawn, $p['drawn']->phase());
    }

    public function test_sql_filter_matches_phase_method(): void
    {
        $this->onePerPhase();

        foreach (PromotionPhase::filterable() as $phase) {
            $viaSql = Promotion::inPhase($phase)->pluck('id')->sort()->values()->all();
            $viaPhp = Promotion::all()->filter(fn (Promotion $p) => $p->phase() === $phase)->pluck('id')->sort()->values()->all();

            $this->assertSame($viaPhp, $viaSql, "Filtro divergente para {$phase->value}");
        }
    }

    public function test_numbers_are_zero_padded_to_the_last_number(): void
    {
        $promotion = Promotion::factory()->make(['number_start' => 1, 'total_numbers' => 1000]);

        $this->assertSame('0007', $promotion->formatNumber(7));
        $this->assertSame('1000', $promotion->formatNumber(1000));
        $this->assertSame('07', Promotion::factory()->make(['number_start' => 0, 'total_numbers' => 100])->formatNumber(7));
    }

    public function test_counts_only_confirmed_as_sold_and_ignore_expired_reservations(): void
    {
        $promotion = Promotion::factory()->open()->create(['total_numbers' => 10]);
        $item = fn (Order $o, int $n) => OrderItem::forceCreate(['order_id' => $o->id, 'promotion_id' => $promotion->id, 'number' => $n]);

        $item(Order::factory()->create(['promotion_id' => $promotion->id, 'status' => OrderStatus::Confirmed]), 1);
        $item(Order::factory()->create(['promotion_id' => $promotion->id]), 2);
        $item(Order::factory()->create(['promotion_id' => $promotion->id, 'reserved_until' => now()->subMinute()]), 3);

        $loaded = Promotion::withNumberCounts()->find($promotion->id);

        $this->assertSame(1, $loaded->sold_count);
        $this->assertSame(1, $loaded->reserved_count);
        $this->assertSame(8, $loaded->availableCount());
        $this->assertSame(10, $loaded->soldPercent());
    }
}
