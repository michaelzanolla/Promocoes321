<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_users_can_not_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_deactivated_during_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_registration_ignores_role_sent_by_the_browser(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Tentativa',
            'email' => 'tentativa@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
            'is_active' => '1',
        ]);

        $user = User::where('email', 'tentativa@example.com')->firstOrFail();
        $this->assertSame(Role::Participant, $user->role);
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->assertSame(Role::Participant, $user->fresh()->role);
    }
}
