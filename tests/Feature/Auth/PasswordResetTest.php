<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_reset_link_can_not_change_the_password_of_another_email(): void
    {
        Notification::fake();

        $attacker = User::factory()->create(['email' => 'atacante@example.com']);
        $victim = User::factory()->create(['email' => 'vitima@example.com']);
        $victimHash = $victim->password;

        $this->post(route('password.request'), ['email' => $attacker->email]);

        Notification::assertSentTo($attacker, ResetPassword::class, function ($notification) use ($victim, $victimHash) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $victim->email,
                'password' => 'senha-trocada-123',
                'password_confirmation' => 'senha-trocada-123',
            ])->assertSessionHasErrors('email');

            $this->assertSame($victimHash, $victim->fresh()->password);

            return true;
        });
    }

    public function test_reset_link_request_does_not_reveal_whether_the_email_has_an_account(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'existe@example.com']);

        $known = $this->from(route('password.request'))->post(route('password.request'), ['email' => 'existe@example.com']);
        $unknown = $this->from(route('password.request'))->post(route('password.request'), ['email' => 'nao-existe@example.com']);
        // Pedir de novo logo em seguida também não pode revelar a conta (o limite só existe para quem tem conta).
        $again = $this->from(route('password.request'))->post(route('password.request'), ['email' => 'existe@example.com']);

        foreach ([$known, $unknown, $again] as $response) {
            $response->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
        }
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_reset_link_work_only_happens_after_the_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        // Sem o middleware que roda o defer() ao fim da requisição: dá para ver o que já aconteceu ao responder.
        $this->withoutMiddleware(InvokeDeferredCallbacks::class);

        $this->post(route('password.request'), ['email' => $user->email])->assertSessionHas('status', __('passwords.sent'));

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);

        app(DeferredCallbackCollection::class)->invoke();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_resetting_the_password_ends_sessions_on_other_devices(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'sessao-de-outro-aparelho', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token, 'email' => $user->email,
                'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_reset_screen_shows_the_email_from_the_link_as_read_only(): void
    {
        $this->get(route('password.reset', ['token' => 'abc', 'email' => 'maria@example.com']))
            ->assertOk()
            ->assertSee('maria@example.com')
            ->assertSee('readonly', false);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }
}
