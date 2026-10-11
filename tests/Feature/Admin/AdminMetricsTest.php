<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AdminMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function payment(Order $order, PaymentStatus $status, int $cents): void
    {
        Payment::forceCreate([
            'order_id' => $order->id, 'provider' => 'asaas', 'provider_payment_id' => Str::random(12),
            'method' => PaymentMethod::Pix, 'amount_cents' => $cents, 'status' => $status,
        ]);
    }

    public function test_reservations_and_pending_payments_are_never_revenue(): void
    {
        $paid = Order::factory()->create(['status' => OrderStatus::Confirmed, 'total_cents' => 1000]);
        $refunded = Order::factory()->create(['status' => OrderStatus::Refunded, 'total_cents' => 500]);
        $waiting = Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'total_cents' => 300]);
        Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'total_cents' => 999, 'reserved_until' => now()->subMinute()]);

        $this->payment($paid, PaymentStatus::Confirmed, 1000);
        $this->payment($refunded, PaymentStatus::Refunded, 500);
        $this->payment($waiting, PaymentStatus::Pending, 300);

        $this->assertSame([
            'gross' => 1500,
            'received' => 1000,
            'refunded' => 500,
            'pending' => 300,
        ], app(AdminMetrics::class)->money());
    }

    public function test_order_counts_by_group(): void
    {
        Order::factory()->create(['status' => OrderStatus::Confirmed]);
        Order::factory()->create(['status' => OrderStatus::Processing]);
        Order::factory()->create(['status' => OrderStatus::Expired]);
        Order::factory()->create(['status' => OrderStatus::Cancelled]);
        Order::factory()->create(['status' => OrderStatus::NeedsReview]);

        $this->assertSame(
            ['pending' => 1, 'confirmed' => 1, 'cancelled_or_expired' => 2, 'needs_review' => 1],
            app(AdminMetrics::class)->orders(),
        );
    }
}
