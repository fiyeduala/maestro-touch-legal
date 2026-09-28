<?php

namespace Tests;

use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use App\Http\Middleware\EnsureStaffSessionVerified;
use App\Models\User;
use App\Support\SiteUrl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Settings memoises per process; each test starts from its own (fresh) database.
        Settings::flush();
    }

    /** A user holding the given roles (granted directly, as a fixture). */
    protected function userWithRoles(Role ...$roles): User
    {
        $user = User::factory()->create(['has_email_authentication' => true]);
        foreach ($roles as $role) {
            $user->roleGrants()->create(['role' => $role->value, 'granted_at' => now()]);
        }
        $user->flushRoleCache();

        return $user;
    }

    /**
     * Laravel's test client trims trailing slashes, but public URLs end in "/" (WordPress style)
     * and the bare form is 301-redirected. Keep the slash so tests request the real address.
     */
    protected function prepareUrlForRequest($uri)
    {
        $prepared = parent::prepareUrlForRequest($uri);
        $path = parse_url((string) $uri, PHP_URL_PATH) ?? '';

        if ($path === '' || $path === '/' || ! str_ends_with($path, '/')) {
            return $prepared;
        }

        [$base, $query] = array_pad(explode('?', $prepared, 2), 2, null);

        return rtrim($base, '/').'/'.($query !== null ? '?'.$query : '');
    }

    /** The public, trailing-slash form of a path, as redirects and canonical links use it. */
    protected function site(string $path): string
    {
        return SiteUrl::to($path);
    }

    /** Signs in to the staff panel as if the password and 2-step challenge had passed. */
    protected function actingAsStaff(User $user): static
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $this->actingAs($user)->withSession([
            EnsureStaffSessionVerified::SESSION_KEY => ['user_id' => $user->getKey(), 'at' => now()->getTimestamp()],
        ]);
    }
}
