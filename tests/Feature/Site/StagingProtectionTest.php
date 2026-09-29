<?php

namespace Tests\Feature\Site;

use App\Domain\Billing\PaystackGateway;
use App\Http\Middleware\ProtectNonProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** A staging copy stays private, unindexed, unable to email clients and unable to take real money (D37). */
class StagingProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function staging(bool $withCredentials = true): void
    {
        $this->app['env'] = 'staging';
        config(['staging.protect' => true, 'staging.user' => $withCredentials ? 'tester' : null,
            'staging.password_hash' => $withCredentials ? Hash::make('correct horse battery') : null]);
    }

    private function basic(string $user, string $password): array
    {
        return ['Authorization' => 'Basic '.base64_encode("{$user}:{$password}")];
    }

    public function test_staging_without_credentials_stays_locked(): void
    {
        $this->staging(withCredentials: false);

        $this->get('/')->assertStatus(503)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin/login')->assertStatus(503);
        // The signed webhook still reaches its own checks (and refuses the bad signature itself).
        $this->postJson('/webhooks/paystack', ['event' => 'charge.success'])->assertStatus(401);
    }

    public function test_staging_needs_the_password_on_every_page(): void
    {
        $this->staging();

        $this->get('/')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->get('/blog/', $this->basic('tester', 'wrong password'))->assertStatus(401);
        $this->get('/log-in/', $this->basic('tester', 'correct horse battery'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/robots.txt', $this->basic('tester', 'correct horse battery'))->assertOk()->assertSee('Disallow: /');

        for ($i = 0; $i < 10; $i++) {
            $this->get('/', $this->basic('tester', "guess {$i}"));
        }
        $this->get('/', $this->basic('tester', 'correct horse battery'))->assertStatus(429);
    }

    public function test_production_is_neither_gated_nor_noindexed(): void
    {
        $this->app['env'] = 'production';
        config(['staging.protect' => true]);

        $response = $this->get('/log-in/')->assertOk();
        $this->assertFalse($response->headers->has('X-Robots-Tag'));
    }

    public function test_staging_email_goes_only_to_the_tester_or_the_log(): void
    {
        $this->app['env'] = 'staging';
        config(['mail.default' => 'array', 'staging.mail_to' => 'tester@example.test']);
        ProtectNonProduction::configureMail();
        Mail::raw('Hello', fn ($m) => $m->to('client@example.com')->subject('Test'));

        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame(['tester@example.test'], array_map(fn ($a) => $a->getAddress(), $sent[0]->getEnvelope()->getRecipients()));

        config(['mail.default' => 'smtp', 'staging.mail_to' => null]);
        ProtectNonProduction::configureMail();
        $this->assertSame('log', config('mail.default'));
    }

    public function test_live_paystack_keys_only_work_in_production(): void
    {
        config(['services.paystack.secret_key' => 'sk_live_'.str_repeat('x', 20)]);
        $gateway = app(PaystackGateway::class);

        $this->app['env'] = 'staging';
        $this->assertFalse($gateway->configured());
        $this->assertTrue($gateway->liveKeyRefused());

        $this->app['env'] = 'production';
        $this->assertTrue($gateway->configured());

        config(['services.paystack.secret_key' => 'sk_test_'.str_repeat('x', 20)]);
        $this->app['env'] = 'staging';
        $this->assertTrue($gateway->configured());
    }
}
