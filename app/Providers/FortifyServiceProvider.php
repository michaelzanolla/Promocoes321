<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // "Esqueci minha senha": e-mail sem conta ou pedido repetido recebem a mesma resposta de sucesso,
        // para a tela não revelar quais e-mails estão cadastrados.
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, fn () => new SuccessfulPasswordResetLinkRequestResponse(Password::RESET_LINK_SENT));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureLoginAudit();
    }

    /**
     * Logins e tentativas recusadas vão para a Auditoria do painel.
     * Nunca grava a senha digitada (o evento Failed a carrega): só o e-mail.
     */
    private function configureLoginAudit(): void
    {
        Event::listen(fn (Login $event) => $event->user instanceof User
            ? AuditLog::record('auth.login', $event->user, $event->remember ? ['lembrar de mim' => 'sim'] : [], $event->user)
            : null);

        Event::listen(function (Failed $event) {
            $email = Str::lower((string) ($event->credentials[Fortify::username()] ?? ''));
            AuditLog::record('auth.failed', User::where('email', $email)->first(), ['e-mail' => Str::limit($email, 255, '')]);
        });

        Event::listen(fn (TwoFactorAuthenticationFailed $event) => AuditLog::record('auth.two_factor_failed', $event->user));
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Igual ao login padrão do Fortify, mas recusa contas desativadas pelo administrador.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', $request->string(Fortify::username()))->first();

            if (! $user || ! Hash::check($request->string('password'), $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                AuditLog::record('auth.inactive', $user, ['e-mail' => $user->email]);

                throw ValidationException::withMessages([
                    Fortify::username() => 'Esta conta está desativada. Fale com o suporte.',
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers) use ($throttleKey) {
                // Um registro por minuto por e-mail+IP: um robô insistindo não enche a Auditoria.
                if (Cache::add('audit:login-throttled:'.sha1($throttleKey), true, 60)) {
                    $email = Str::lower((string) $request->input(Fortify::username()));
                    AuditLog::record('auth.throttled', User::where('email', $email)->first(), ['e-mail' => Str::limit($email, 255, '')]);
                }

                return back()->withInput($request->only(Fortify::username()))->withErrors([
                    Fortify::username() => trans('auth.throttle', ['seconds' => $headers['Retry-After'] ?? 60]),
                ]);
            });
        });

        // Aplicado a todas as rotas do Fortify (config/fortify.php): cadastro, recuperação de senha etc.
        RateLimiter::for('auth-forms', function (Request $request) {
            return $request->isMethod('GET') ? Limit::none() : Limit::perMinute(10)->by($request->ip());
        });
    }
}
