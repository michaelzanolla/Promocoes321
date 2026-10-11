<?php

namespace App\Providers;

use App\Models\User;
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
        //
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
        $who = fn (Request $request): string => $request->user() ? "user:{$request->user()->id}" : "ip:{$request->ip()}";

        // Reservas prendem números de verdade: limite baixo para dificultar quem tenta segurar a promoção inteira.
        RateLimiter::for('reserve', fn (Request $request) => Limit::perMinute(10)->by($who($request)));
        // A grade consulta a situação a cada 5 s; 60/min cobre uma aba aberta com folga.
        RateLimiter::for('numbers-status', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        // Páginas de pedido: limita tentativas de adivinhar códigos.
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(30)->by($who($request)));
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
