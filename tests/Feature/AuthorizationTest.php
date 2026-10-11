<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_access_admin_area(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->can('access-admin'));
        $this->assertFalse(User::factory()->create()->can('access-admin'));
    }

    public function test_participant_sees_only_own_orders(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue($owner->can('view', $order));
        $this->assertFalse($other->can('view', $order));
    }

    public function test_admin_can_see_any_order(): void
    {
        $order = Order::factory()->create();

        $this->assertTrue(User::factory()->admin()->create()->can('view', $order));
    }

    public function test_guest_order_is_not_visible_to_logged_in_strangers(): void
    {
        $order = Order::factory()->create(['user_id' => null]);

        $this->assertFalse(User::factory()->create()->can('view', $order));
    }
}
