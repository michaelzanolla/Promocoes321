<?php

namespace App\Providers;

use App\Models\User;
use App\Payments\AsaasGateway;
use App\Payments\PaymentGateway;
use App\Support\ClientKey;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new AsaasGateway(
            config('services.asaas.base_url'),
            config('services.asaas.api_key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());

        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        $ip = fn (Request $request): string => ClientKey::of($request->ip());
        $who = fn (Request $request): string => $request->user() ? "user:{$request->user()->id}" : 'ip:'.$ip($request);

        // Reservas prendem números de verdade: limite baixo para dificultar quem tenta segurar a promoção inteira.
        RateLimiter::for('reserve', fn (Request $request) => Limit::perMinute(10)->by($who($request)));
        // A grade consulta a situação a cada 5 s (12/min por aba). A resposta fica 3 s em cache, então o limite
        // pode ser folgado: muita gente divide o mesmo IP (Wi-Fi, operadora móvel).
        RateLimiter::for('numbers-status', fn (Request $request) => Limit::perMinute(300)->by($ip($request)));
        // Páginas de pedido: limita tentativas de adivinhar códigos.
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(30)->by($who($request)));
        // Consulta de pedido por e-mail: poucas tentativas, para não virar ferramenta de envio de spam.
        RateLimiter::for('order-lookup', fn (Request $request) => Limit::perMinute(5)->by($ip($request)));
        // A página do pedido consulta a situação a cada 5 s.
        RateLimiter::for('order-status', fn (Request $request) => Limit::perMinute(30)->by($who($request)));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
