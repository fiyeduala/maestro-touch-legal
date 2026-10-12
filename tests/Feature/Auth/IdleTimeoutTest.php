<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Role;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureStaffSessionVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function idleFor(int $minutes): array
    {
        return [EnforceIdleTimeout::SESSION_KEY => now()->subMinutes($minutes)->getTimestamp()];
    }

    public function test_the_session_outlives_both_idle_limits(): void
    {
        $this->assertSame(1440, config('session.staff_idle_minutes'));
        $this->assertGreaterThanOrEqual(config('session.staff_idle_minutes'), config('session.lifetime'));
        $this->assertGreaterThanOrEqual(config('session.client_idle_minutes'), config('session.lifetime'));
    }

    public function test_staff_stay_signed_in_for_24_hours_without_activity(): void
    {
        $lawyer = $this->userWithRoles(Role::Lawyer);

        $this->actingAsStaff($lawyer)->withSession($this->idleFor(23 * 60))->get('/admin')->assertOk();
        $this->assertAuthenticatedAs($lawyer);
        $this->assertGreaterThan(now()->subMinute()->getTimestamp(), session(EnforceIdleTimeout::SESSION_KEY));
    }

    public function test_staff_are_signed_out_after_24_hours_without_activity(): void
    {
        $admin = $this->userWithRoles(Role::TechnicalAdministrator);

        $this->actingAsStaff($admin)->withSession($this->idleFor(24 * 60 + 1))->get('/admin')->assertRedirect();
        $this->assertGuest();
        $this->assertNull(session(EnsureStaffSessionVerified::SESSION_KEY));
    }

    public function test_clients_keep_the_shorter_limit(): void
    {
        $client = $this->userWithRoles(Role::Client);

        $this->actingAs($client)->withSession($this->idleFor(60))->get(route('portal.home'));
        $this->assertAuthenticatedAs($client);

        $this->actingAs($client)->withSession($this->idleFor(config('session.client_idle_minutes') + 1))->get(route('portal.home'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_staff_not_signed_in_through_the_staff_login_get_the_shorter_limit(): void
    {
        // Only a session that came through the staff login (password + 2-step) gets 24 hours.
        $lawyer = $this->userWithRoles(Role::Lawyer);

        $this->actingAs($lawyer)->withSession($this->idleFor(config('session.client_idle_minutes') + 1))->get('/');
        $this->assertGuest();
    }
}
