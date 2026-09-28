<?php

namespace App\Providers;

use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use App\Filament\Auth\StaffLoginResponse;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoginResponse::class, StaffLoginResponse::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // No external breach-check call: shared hosting outbound requests are unreliable (DECISIONS D9).
        Password::defaults(fn () => Password::min(12)->letters()->numbers()->max(200));

        Gate::define('manage-content', fn (User $user) => $user->isActive()
            && $user->hasRole(Role::TechnicalAdministrator, Role::FirmPrincipal, Role::ContentEditor));
        Gate::define('publish-content', fn (User $user) => $user->isFullAdministrator()
            || ($user->isActive() && $user->hasRole(Role::ContentEditor) && (bool) Settings::get('content.editors_can_publish')));

        RateLimiter::for('public-forms', fn (Request $request) => [
            Limit::perMinute(5)->by('form:'.$request->ip()),
            Limit::perHour(30)->by('form-h:'.$request->ip()),
        ]);
        RateLimiter::for('auth-forms', fn (Request $request) => Limit::perMinute(10)->by('auth:'.$request->ip()));
    }
}
