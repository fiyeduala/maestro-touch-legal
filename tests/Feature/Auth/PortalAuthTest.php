<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com',
            'password' => 'correct-horse-9battery', 'password_confirmation' => 'correct-horse-9battery',
            'accept_terms' => '1', 'accept_privacy' => '1',
        ];
    }

    public function test_registration_response_does_not_reveal_existing_accounts(): void
    {
        Notification::fake();

        $new = $this->post('/register/', $this->registration());
        $again = $this->post('/register/', $this->registration(['first_name' => 'Someone']));

        $new->assertRedirect(route('login'));
        $again->assertRedirect(route('login'));
        $this->assertSame($new->getSession()->get('status'), $again->getSession()->get('status'));
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
        $this->assertGuest();
    }

    public function test_registration_requires_both_consents_and_a_strong_password(): void
    {
        $this->post('/register/', $this->registration(['accept_privacy' => null, 'password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors(['accept_privacy', 'password']);
    }

    public function test_staff_cannot_use_the_client_login(): void
    {
        $staff = $this->userWithRoles(Role::Lawyer);

        $this->post('/log-in/', ['email' => $staff->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_wrong_password_and_unknown_email_look_the_same(): void
    {
        $client = $this->userWithRoles(Role::Client);

        $wrong = $this->post('/log-in/', ['email' => $client->email, 'password' => 'nope-nope-nope'])->getSession()->get('errors')->first('email');
        $unknown = $this->post('/log-in/', ['email' => 'nobody@example.com', 'password' => 'nope-nope-nope'])->getSession()->get('errors')->first('email');

        $this->assertSame($wrong, $unknown);
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login_failed']);
    }

    public function test_client_signs_in_to_the_portal(): void
    {
        $client = $this->userWithRoles(Role::Client);
        $client->forceFill(['email_verified_at' => now()])->save();

        $this->post('/log-in/', ['email' => $client->email, 'password' => 'password'])->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($client);
        $this->get('/portal')->assertOk();
    }
}
