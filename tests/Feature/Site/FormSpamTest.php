<?php

namespace Tests\Feature\Site;

use App\Models\Enquiry;
use App\Support\FormGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Spam screening on the public forms (D48). */
class FormSpamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['forms.min_seconds' => 3]);
    }

    private function contact(array $overrides = [], int $secondsAgo = 30): array
    {
        return $overrides + [
            FormGuard::STAMP => Crypt::encryptString((string) (now()->getTimestamp() - $secondsAgo)),
            'contact_name' => 'Chidi Eze',
            'contact_email' => 'chidi@example.test',
            'summary' => 'I want to register a new company in Lagos.',
            'consent' => '1',
        ];
    }

    public function test_a_person_taking_normal_time_gets_through(): void
    {
        $this->post('/contact/', $this->contact())->assertSessionHasNoErrors()->assertRedirectContains('/legal-assistance/thank-you/');
        $this->assertSame(1, Enquiry::count());
    }

    public function test_instant_submissions_are_dropped_without_saying_so(): void
    {
        $this->post('/contact/', $this->contact(secondsAgo: 1))->assertSessionHasNoErrors()->assertRedirectContains('/legal-assistance/thank-you/');
        $this->assertSame(0, Enquiry::count());
        Notification::assertNothingSent();
    }

    public function test_a_missing_or_forged_stamp_asks_the_person_to_send_again(): void
    {
        $this->post('/contact/', $this->contact([FormGuard::STAMP => '']))->assertSessionHasErrors('form');
        $this->post('/contact/', $this->contact([FormGuard::STAMP => (string) (now()->getTimestamp() - 60)]))->assertSessionHasErrors('form');
        $this->assertSame(0, Enquiry::count());
    }

    public function test_link_heavy_messages_are_refused_but_one_link_is_fine(): void
    {
        $this->post('/contact/', $this->contact(['summary' => 'Cheap SEO http://a.test and www.b.test [url=c.test]']))
            ->assertSessionHasErrors('summary');
        $this->post('/contact/', $this->contact(['contact_name' => 'Win http://x.test', 'summary' => 'See https://y.test for details please.']))
            ->assertSessionHasErrors('contact_name');
        $this->assertSame(0, Enquiry::count());

        $this->post('/contact/', $this->contact(['summary' => 'The company record is at https://search.cac.gov.ng please check.']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Enquiry::count());
    }

    public function test_forms_carry_the_stamp_and_show_turnstile_only_when_configured(): void
    {
        $this->get('/join-our-legal-team/')->assertOk()->assertSee('name="form_started"', false)->assertDontSee('cf-turnstile', false);

        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
        $this->get('/join-our-legal-team/')->assertSee('data-sitekey="site-key"', false);
    }

    public function test_turnstile_is_checked_once_configured(): void
    {
        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->post('/contact/', $this->contact())->assertSessionHasErrors('form');
        $this->post('/contact/', $this->contact(['cf-turnstile-response' => 'bad']))->assertSessionHasErrors('form');
        $this->post('/contact/', $this->contact(['cf-turnstile-response' => 'good']))->assertSessionHasNoErrors();
        $this->assertSame(1, Enquiry::count());

        Http::assertSent(fn ($request) => $request['secret'] === 'secret-key' && $request['response'] === 'good');
    }
}
