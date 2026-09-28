<?php

namespace Tests\Concerns;

use App\Domain\Clients\ClientContacts;
use App\Domain\Engagement\Engagements;
use App\Domain\Engagement\Quotations;
use App\Domain\Identity\Invitations;
use App\Domain\Identity\Role;
use App\Domain\Intake\ConflictChecks;
use App\Domain\Intake\Enquiries;
use App\Domain\Intake\EnquirySource;
use App\Models\Client;
use App\Models\EngagementTemplate;
use App\Models\Enquiry;
use App\Models\Invitation;
use App\Models\Matter;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Builds the enquiry → engagement → matter chain through the real domain services. */
trait PracticeFixtures
{
    protected User $admin;

    protected User $lawyer;

    protected function setUpPractice(): void
    {
        $this->admin = $this->userWithRoles(Role::FirmPrincipal);
        $this->lawyer = $this->userWithRoles(Role::Lawyer);
    }

    protected function service(): Service
    {
        return Service::firstOrCreate(['slug' => 'business-registration'], [
            'name' => 'Business registration', 'is_public' => true, 'is_active' => true, 'sort' => 1,
        ]);
    }

    protected function enquiry(array $overrides = []): Enquiry
    {
        return app(Enquiries::class)->submitPublic($overrides + [
            'contact_name' => 'Ada Obi',
            'contact_email' => 'ada'.uniqid().'@example.test',
            'summary' => 'I need help registering my company.',
        ], $this->service(), EnquirySource::Website);
    }

    /** Assigned to the lawyer, conflict cleared, client record created. */
    protected function readyEnquiry(): Enquiry
    {
        $enquiry = $this->enquiry();
        app(Enquiries::class)->assign($enquiry, $this->lawyer, $this->admin);
        app(ConflictChecks::class)->decide($enquiry->refresh(), 'cleared', 'Searched all parties; no matches found.', $this->lawyer);
        app(Enquiries::class)->createClient($enquiry->refresh(), $this->lawyer);

        return $enquiry->refresh();
    }

    /** A verified portal user linked to the client through the real invitation flow. */
    protected function clientContact(Client $client, string $name = 'Ada Obi'): User
    {
        [$invitation] = app(ClientContacts::class)->invite($client, 'contact'.uniqid().'@example.test', $name, $this->admin);

        return app(Invitations::class)->acceptNew(Invitation::find($invitation->id), $name, 'a-Long-password-123');
    }

    protected function template(): EngagementTemplate
    {
        return EngagementTemplate::firstOrCreate(['name' => 'Standard terms'], [
            'body' => '<p>Terms for {client_name} ({client_reference}) – {engagement_title}.</p><script>alert(1)</script>',
            'version' => 1, 'is_active' => true,
        ]);
    }

    /** Runs the whole chain and returns the opened matter plus the client contact. */
    protected function openMatter(): array
    {
        $enquiry = $this->readyEnquiry();
        $contact = $this->clientContact($enquiry->client);
        $engagements = app(Engagements::class);

        $engagement = $engagements->create($enquiry, $this->template(), 'Company registration', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $engagement->refresh();
        $engagements->respond($engagement, $engagement->current_version_id, 'accepted', 'Ada Obi', null, $contact, '127.0.0.1', 'test');
        $matter = $engagements->approve($engagement->refresh(), $this->lawyer, 'Approved', $this->admin);

        return [$matter->refresh(), $contact, $enquiry->refresh(), $engagement->refresh()];
    }

    protected function quotationData(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Registration fees',
            'currency' => 'NGN',
            'scope' => 'Registering a private company with CAC.',
            'lines' => [['kind' => 'fee', 'description' => 'Professional fee', 'quantity' => 1, 'unit' => '150000.00'],
                ['kind' => 'expense', 'description' => 'Filing fee', 'quantity' => 1, 'unit' => '25000.50']],
            'valid_until' => now()->addDays(14)->toDateString(),
        ];
    }

    protected function pdf(string $name = 'file.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    protected function quotations(): Quotations
    {
        return app(Quotations::class);
    }

    protected function teamMember(Matter $matter, Role $role = Role::CaseOfficer): User
    {
        $user = $this->userWithRoles($role);
        app(\App\Domain\Matters\Matters::class)->addTeamMember($matter, $user, $this->admin);

        return $user;
    }
}
