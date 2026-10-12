<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureUserIsActive;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Painel administrativo em /admin.
 * Sem tela de login própria: quem não está logado vai para o login do site (Fortify),
 * que já tem 2FA, limite de tentativas e bloqueio de contas desativadas.
 * O acesso é decidido por User::canAccessPanel() (só administradores ativos).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('app.name').' · Painel')
            ->darkMode(false)
            // Inter servida pelo próprio site (publicada pelo filament:upgrade no composer install), sem chamar o Bunny Fonts.
            ->font('Inter Variable', url: asset('fonts/filament/filament/inter/index.css'), provider: LocalFontProvider::class)
            ->colors([
                // Escala manual: a gerada a partir de um hex escuro sai saturada demais (ciano).
                'primary' => [
                    50 => '#eef4f5', 100 => '#d8e6e8', 200 => '#b3cdd1', 300 => '#82abb1', 400 => '#4f8189',
                    500 => '#2f626a', 600 => '#1f4b53', 700 => '#12343b', 800 => '#0e2a30', 900 => '#0b2226', 950 => '#06161a',
                ],
                'warning' => Color::hex('#f0b000'),
                'danger' => Color::hex('#b8322a'),
                'success' => Color::hex('#1d7a4c'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsActive::class,
            ]);
    }
}
