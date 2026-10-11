<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_admin(): void
    {
        $this->artisan('app:create-admin', ['email' => 'dono@example.com'])
            ->expectsQuestion('Nome', 'Dono')
            ->expectsQuestion('Senha', 'uma-senha-qualquer')
            ->assertSuccessful();

        $user = User::where('email', 'dono@example.com')->firstOrFail();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, AuditLog::where('action', 'user.promoted_to_admin')->count());
    }

    public function test_promotes_an_existing_user_after_confirmation(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:create-admin', ['email' => $user->email])
            ->expectsConfirmation("Promover {$user->name} <{$user->email}> a administrador?", 'yes')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_does_not_promote_without_confirmation(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:create-admin', ['email' => $user->email])
            ->expectsConfirmation("Promover {$user->name} <{$user->email}> a administrador?", 'no')
            ->assertFailed();

        $this->assertFalse($user->fresh()->isAdmin());
    }
}
