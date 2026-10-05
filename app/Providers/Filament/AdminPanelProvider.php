<?php

namespace App\Providers\Filament;

use App\Domain\Operations\Settings;
use App\Http\Middleware\EnsureStaffSessionVerified;
use App\Notifications\StaffLoginCode;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Staff portal at /admin. Every staff account must use 2-step verification
 * (authenticator app with recovery codes, or an emailed code). The bell lists in-app notifications;
 * the button beside it turns on push notifications for this browser (D49).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->brandName('Maestro Touch Legal')
                    ->recoverable()
                    ->regenerableRecoveryCodes(),
                EmailAuthentication::make()
                    ->codeNotification(StaffLoginCode::class)
                    ->codeExpiryMinutes(10),
            ], isRequired: true)
            ->brandName('Maestro Touch Legal')
            ->brandLogo(fn () => url(Settings::get('site.logo_path')))
            ->brandLogoHeight('2.25rem')
            ->favicon(fn () => url(Settings::get('site.favicon_path')))
            ->colors([
                'primary' => Color::hex('#007bf8'),
                'gray' => Color::Slate,
            ])
            ->font('Poppins', url: fn () => Vite::asset('resources/css/admin-font.css'), provider: LocalFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn (): string => Blade::render("@include('partials.push-toggle', ['variant' => 'panel'])"))
            ->navigationGroups(['Website', 'People', 'System'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureStaffSessionVerified::class,
            ], isPersistent: true);
    }
}
