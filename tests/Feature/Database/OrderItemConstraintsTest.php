<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A garantia contra venda duplicada mora no banco (docs/fase-1-planejamento.md, seção 4).
 */
class OrderItemConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private function item(Order $order, int $number, ?int $active = 1): OrderItem
    {
        return OrderItem::forceCreate([
            'order_id' => $order->id,
            'promotion_id' => $order->promotion_id,
            'number' => $number,
            'active' => $active,
        ]);
    }

    public function test_same_number_can_not_be_active_in_two_orders(): void
    {
        $first = Order::factory()->create();
        $second = Order::factory()->create(['promotion_id' => $first->promotion_id]);

        $this->item($first, 12);

        $this->expectException(QueryException::class);
        $this->item($second, 12);
    }

    public function test_released_numbers_keep_history_and_can_be_taken_again(): void
    {
        $first = Order::factory()->create();
        $second = Order::factory()->create(['promotion_id' => $first->promotion_id]);
        $third = Order::factory()->create(['promotion_id' => $first->promotion_id]);

        $this->item($first, 12, null);
        $this->item($second, 12, null);
        $this->item($third, 12);

        $this->assertSame(3, OrderItem::where('number', 12)->count());
        $this->assertSame(1, OrderItem::where('number', 12)->where('active', 1)->count());
    }

    public function test_same_number_in_different_promotions_is_allowed(): void
    {
        $this->item(Order::factory()->create(), 12);
        $this->item(Order::factory()->create(), 12);

        $this->assertSame(2, OrderItem::where('active', 1)->count());
    }

    public function test_active_flag_only_accepts_one_or_null(): void
    {
        $this->expectException(QueryException::class);
        $this->item(Order::factory()->create(), 12, 0);
    }

    public function test_orders_with_items_can_not_be_deleted(): void
    {
        $order = Order::factory()->create();
        $this->item($order, 1);

        $this->expectException(QueryException::class);
        $order->delete();
    }

    public function test_buyer_cpf_is_encrypted_at_rest(): void
    {
        $order = Order::factory()->create(['buyer_cpf' => '12345678909']);

        $raw = $order->getConnection()->table('orders')->where('id', $order->id)->value('buyer_cpf');

        $this->assertNotSame('12345678909', $raw);
        $this->assertSame('12345678909', $order->fresh()->buyer_cpf);
    }
}
