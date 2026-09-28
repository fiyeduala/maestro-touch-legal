<?php

namespace Tests\Feature\Site;

use App\Domain\Intake\EnquirySource;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\Intake\ServiceCatalogue;
use App\Models\Enquiry;
use App\Models\Service;
use App\Notifications\Intake\EnquiryAcknowledgement;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** The public "legal assistance" and Contact-page enquiry forms. */
class PublicEnquiryTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    private Service $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpPractice();
        $this->svc = $this->service();
        $catalogue = app(ServiceCatalogue::class);
        $form = $catalogue->saveFormDraft($this->svc, [
            ['label' => 'Proposed company name', 'key' => 'company_name', 'type' => 'text', 'required' => true],
            ['label' => 'Company type', 'key' => 'company_type', 'type' => 'select', 'options' => ['Private limited', 'Business name']],
        ], $this->admin);
        $catalogue->publishForm($form, $this->admin);
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'service' => $this->svc->slug,
            'contact_name' => 'Chidi Eze',
            'contact_email' => 'Chidi@Example.test',
            'summary' => 'I want to register a new company in Lagos.',
            'answers' => ['company_name' => 'Eze Ventures', 'company_type' => 'Private limited'],
            'consent' => '1',
        ];
    }

    public function test_service_list_and_questions_render(): void
    {
        Service::create(['name' => 'Hidden service', 'slug' => 'hidden', 'is_public' => false, 'is_active' => true]);

        $this->get('/legal-assistance/')->assertOk()->assertSee('Business registration')->assertDontSee('Hidden service');
        $this->get('/legal-assistance/?service='.$this->svc->slug)->assertOk()
            ->assertSee('Proposed company name')->assertSee('Private limited')
            ->assertSee('does not make Maestro Touch Legal your lawyers');
    }

    public function test_enquiry_is_recorded_with_answers_and_acknowledged(): void
    {
        $this->post('/legal-assistance/', $this->valid())->assertRedirectContains('/legal-assistance/thank-you/');

        $enquiry = Enquiry::sole();
        $this->assertSame(EnquiryStatus::New, $enquiry->status);
        $this->assertSame(EnquirySource::Website, $enquiry->source);
        $this->assertSame('chidi@example.test', $enquiry->contact_email);
        $this->assertSame($this->svc->publishedForm->id, $enquiry->intake_form_id);
        $this->assertStringContainsString('Eze Ventures', json_encode($enquiry->answers));
        $this->assertNull($enquiry->client_id);
        Notification::assertSentOnDemand(EnquiryAcknowledgement::class);

        $this->get('/legal-assistance/thank-you/')->assertOk()->assertSee($enquiry->reference);
    }

    public function test_required_answers_consent_and_choices_are_enforced(): void
    {
        $this->from('/legal-assistance/')->post('/legal-assistance/', $this->valid([
            'answers' => ['company_type' => 'Something else'], 'consent' => null,
        ]))->assertSessionHasErrors(['answers.company_name', 'answers.company_type', 'consent']);

        $this->post('/legal-assistance/', $this->valid(['service' => 'not-a-service']))->assertSessionHasErrors('service');

        $this->svc->update(['is_active' => false]);
        $this->post('/legal-assistance/', $this->valid())->assertSessionHasErrors('service');
        $this->assertSame(0, Enquiry::count());
    }

    public function test_honeypot_submission_is_silently_dropped(): void
    {
        $this->post('/legal-assistance/', $this->valid(['company_website' => 'http://spam.test']))->assertRedirectContains('/legal-assistance/thank-you/');
        $this->post('/contact/', $this->valid(['company_website' => 'http://spam.test']))->assertRedirectContains('/legal-assistance/thank-you/');
        $this->assertSame(0, Enquiry::count());
    }

    public function test_contact_page_form_records_a_contact_enquiry(): void
    {
        $this->seed(PageSeeder::class);
        $this->get('/contact/')->assertOk()->assertSee('Send Us a Message');

        $this->post('/contact/', [
            'contact_name' => 'Ngozi Ade', 'contact_email' => 'ngozi@example.test',
            'summary' => 'Please call me about a tenancy dispute.', 'consent' => '1',
        ])->assertRedirectContains('/legal-assistance/thank-you/');

        $enquiry = Enquiry::sole();
        $this->assertSame(EnquirySource::ContactForm, $enquiry->source);
        $this->assertNull($enquiry->service_id);
    }

    public function test_public_forms_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/contact/', ['contact_name' => 'x']);
        }
        $this->post('/contact/', ['contact_name' => 'x'])->assertStatus(429);
    }
}
