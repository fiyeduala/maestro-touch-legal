<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Role;
use App\Models\User;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaffLoginCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_code_is_emailed_straight_away_not_queued(): void
    {
        Queue::fake();
        $user = User::factory()->create(['has_email_authentication' => true]);
        $user->roleGrants()->create(['role' => Role::Lawyer->value, 'granted_at' => now()]);

        $provider = collect(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders())
            ->first(fn ($p) => $p instanceof EmailAuthentication);

        $this->assertSame(10, $provider->getCodeExpiryMinutes());
        $this->assertTrue($provider->sendCode($user));

        Queue::assertNothingPushed();
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Your Maestro Touch Legal sign-in code', $messages[0]->getOriginalMessage()->getSubject());
        $this->assertSame($user->email, $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }
}
