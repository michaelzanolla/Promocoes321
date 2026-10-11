<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Logins e tentativas recusadas aparecem na Auditoria do painel, sem nunca gravar a senha.
 */
class LoginAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_recorded_with_the_user(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'auth.login')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->subject_id);
    }

    public function test_wrong_password_and_unknown_email_are_recorded_without_the_password(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);

        $this->post(route('login.store'), ['email' => 'maria@example.com', 'password' => 'senha-errada-123']);
        $this->post(route('login.store'), ['email' => 'nao-existe@example.com', 'password' => 'outra-senha-456']);

        $logs = AuditLog::where('action', 'auth.failed')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$user->id, null], $logs->pluck('subject_id')->all());
        $this->assertSame(['maria@example.com', 'nao-existe@example.com'], $logs->pluck('changes.e-mail')->all());
        $this->assertNull($logs[0]->user_id);

        $everything = AuditLog::all()->toJson();
        $this->assertStringNotContainsString('senha-errada-123', $everything);
        $this->assertStringNotContainsString('outra-senha-456', $everything);
    }

    public function test_deactivated_account_attempt_is_recorded(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.inactive', 'subject_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login']);
    }

    public function test_too_many_attempts_shows_a_message_and_is_recorded_once(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => "errada-{$i}"]);
        }

        // Bloqueado: volta para o login com aviso (não a página de erro 429), mesmo com a senha certa.
        $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('action', 'auth.throttled')->where('subject_id', $user->id)->count());
    }

    public function test_wrong_two_factor_code_is_recorded(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['code' => '000000']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_failed', 'subject_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login']);
    }
}
