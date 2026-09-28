<?php

namespace Tests\Feature\Practice;

use App\Domain\Documents\Documents;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Identity\Role;
use App\Domain\Matters\Matters;
use App\Domain\Matters\Tasks;
use App\Domain\Matters\TaskStatus;
use App\Domain\RuleViolation;
use App\Models\Document;
use App\Models\Matter;
use App\Notifications\StaffAlert;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

class MatterWorkTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('confidential');
        $this->setUpPractice();
    }

    public function test_matter_visibility_is_team_and_client_scoped(): void
    {
        [$matter, $contact] = $this->openMatter();
        [$other, $otherContact] = $this->openMatter();
        $outsider = $this->userWithRoles(Role::Lawyer);
        $finance = $this->userWithRoles(Role::FinanceOfficer);
        $officer = $this->teamMember($matter);

        $this->assertEqualsCanonicalizing([$matter->id, $other->id], Matter::visibleTo($this->lawyer)->pluck('id')->all());
        $this->assertSame([$matter->id], Matter::visibleTo($officer)->pluck('id')->all());
        $this->assertSame([$matter->id], Matter::visibleTo($contact)->pluck('id')->all());
        $this->assertSame([$other->id], Matter::visibleTo($otherContact)->pluck('id')->all());
        $this->assertSame([], Matter::visibleTo($outsider)->pluck('id')->all());
        $this->assertSame([], Matter::visibleTo($finance)->pluck('id')->all());
        $this->assertFalse($outsider->can('view', $matter));
        $this->assertFalse($contact->can('view', $matter), 'Clients use the portal abilities, never staff ones.');
    }

    public function test_removing_a_team_member_ends_access_and_unassigns_tasks(): void
    {
        [$matter] = $this->openMatter();
        $officer = $this->teamMember($matter);
        $task = app(Tasks::class)->create($matter, ['title' => 'Draft memo', 'assignee_id' => $officer->id], $this->lawyer);
        Notification::assertSentTo($officer, StaffAlert::class);

        $membership = $matter->activeTeam()->where('user_id', $officer->id)->first();
        app(Matters::class)->removeTeamMember($membership, 'Reassigned to another team', $this->admin);

        $this->assertFalse($officer->can('view', $matter->refresh()));
        $this->assertNull($task->refresh()->assignee_id);
    }

    public function test_responsible_lawyer_cannot_be_removed_without_replacement(): void
    {
        [$matter] = $this->openMatter();

        $this->expectException(RuleViolation::class);
        app(Matters::class)->removeTeamMember($matter->responsible, 'Leaving the firm', $this->admin);
    }

    public function test_tasks_respect_team_membership_and_dependencies(): void
    {
        [$matter] = $this->openMatter();
        $tasks = app(Tasks::class);
        $outsider = $this->userWithRoles(Role::Lawyer);

        try {
            $tasks->create($matter, ['title' => 'X', 'assignee_id' => $outsider->id], $this->lawyer);
            $this->fail('Assigned a task to someone off the team.');
        } catch (RuleViolation) {
        }

        $first = $tasks->create($matter, ['title' => 'Collect documents'], $this->lawyer);
        $second = $tasks->create($matter, ['title' => 'File application', 'depends_on_id' => $first->id], $this->lawyer);

        try {
            $tasks->complete($second, 'Done', $this->lawyer);
            $this->fail('Completed a task before its dependency.');
        } catch (RuleViolation) {
        }
        try {
            $tasks->update($first, ['depends_on_id' => $second->id], $this->lawyer);
            $this->fail('Created a dependency loop.');
        } catch (RuleViolation) {
        }

        $tasks->complete($first, 'Collected', $this->lawyer);
        $tasks->complete($second->refresh(), 'Filed', $this->lawyer);
        $this->assertSame(TaskStatus::Done, $second->refresh()->status);
    }

    public function test_overdue_tasks_are_reminded_and_escalated_once(): void
    {
        [$matter] = $this->openMatter();
        $officer = $this->teamMember($matter);
        app(Tasks::class)->create($matter, [
            'title' => 'Chase registry', 'assignee_id' => $officer->id,
            'due_at' => now()->subHour(), 'remind_at' => now()->subHours(2),
        ], $this->lawyer);

        $this->assertSame(['reminded' => 1, 'escalated' => 1], app(Tasks::class)->sendDueNotifications());
        $this->assertSame(['reminded' => 0, 'escalated' => 0], app(Tasks::class)->sendDueNotifications());
        Notification::assertSentTo($this->lawyer, StaffAlert::class, fn ($n) => str_contains($n->subject, 'Overdue'));
    }

    public function test_matter_closure_requires_checklist_and_no_open_work(): void
    {
        [$matter] = $this->openMatter();
        $matters = app(Matters::class);
        $task = app(Tasks::class)->create($matter, ['title' => 'Final letter'], $this->lawyer);
        $all = array_fill_keys(array_keys(Matter::CLOSURE_CHECKLIST), true);

        try {
            $matters->close($matter, ['final_documents' => true], 'Completed', $this->lawyer);
            $this->fail('Closed without the checklist.');
        } catch (RuleViolation) {
        }
        try {
            $matters->close($matter, $all, 'Completed', $this->lawyer);
            $this->fail('Closed with an open task.');
        } catch (RuleViolation) {
        }

        app(Tasks::class)->complete($task, 'Sent', $this->lawyer);
        $matters->close($matter->refresh(), $all, 'Registration completed', $this->lawyer);
        $this->assertTrue($matter->refresh()->isClosed());
        $this->assertFalse($this->lawyer->can('update', $matter), 'Closed matters are read-only until reopened.');
        $this->assertFalse($this->lawyer->can('reopen', $matter));

        $matters->reopen($matter, 'Client asked for a certified copy', $this->admin);
        $this->assertFalse($matter->refresh()->isClosed());
        $this->assertTrue($matter->events()->where('type', 'closed')->where('client_visible', true)->exists());
    }

    public function test_deliverable_needs_lawyer_approval_and_client_only_sees_released_version(): void
    {
        [$matter, $contact] = $this->openMatter();
        $officer = $this->teamMember($matter);
        $docs = app(Documents::class);

        $doc = $docs->upload($matter, $this->pdf('draft.pdf'), ['title' => 'Memorandum', 'category' => 'deliverable', 'is_deliverable' => true], $officer);
        $this->assertSame(DocumentStatus::Draft, $doc->status);
        $this->assertFalse($contact->can('viewAsClient', $doc));

        $docs->submitForReview($doc, $officer);
        try {
            $docs->approve($doc->refresh(), null, $officer);
            $this->fail('A case officer gave final approval.');
        } catch (AuthorizationException) {
        }
        try {
            $docs->release($doc->refresh(), null, $officer);
            $this->fail('Released an unapproved draft.');
        } catch (RuleViolation) {
        }

        $docs->approve($doc->refresh(), 'Looks right', $this->lawyer);
        $docs->release($doc->refresh(), null, $officer);
        $doc->refresh();
        $releasedId = $doc->released_version_id;
        $this->assertTrue($contact->can('viewAsClient', $doc));

        // A new internal draft does not change what the client sees.
        $v2 = $docs->addVersion($doc, $this->pdf('draft-2.pdf'), 'Tracked changes', $officer);
        $doc->refresh();
        $this->assertSame(DocumentStatus::Draft, $doc->status);
        $this->assertSame($releasedId, $doc->clientVersion()->id);

        $this->actingAs($contact)->get(route('portal.document-file', $v2))->assertNotFound();
        $this->actingAs($contact)->get(route('portal.document-file', $releasedId))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $acceptance = $docs->clientDecision($doc, $releasedId, 'approved', null, $contact, null, null);
        $this->assertSame($doc->releasedVersion->sha256, $acceptance->content_hash);
        $this->expectException(RuleViolation::class);
        $docs->clientDecision($doc->refresh(), $releasedId, 'approved', null, $contact, null, null);
    }

    public function test_client_uploads_answer_requests_and_other_clients_cannot_download(): void
    {
        [$matter, $contact] = $this->openMatter();
        [, $otherContact] = $this->openMatter();
        $docs = app(Documents::class);

        $request = $docs->requestFromClient($matter, 'Passport photo page', null, null, $this->lawyer);
        $doc = $docs->clientUpload($matter, $this->pdf('passport.pdf'), '', $request->id, null, $contact);
        $this->assertSame('fulfilled', $request->refresh()->status);
        $this->assertSame('Passport photo page', $doc->title);

        $version = $doc->currentVersion;
        $this->actingAs($otherContact)->get(route('portal.document-file', $version))->assertForbidden();
        $this->actingAs($contact)->get(route('portal.document-file', $version))->assertOk();
        $this->actingAsStaff($this->userWithRoles(Role::Lawyer))->get(route('admin.document-file', $version))->assertForbidden();
        $this->actingAsStaff($this->lawyer)->get(route('admin.document-file', $version))->assertOk();

        try {
            $docs->clientUpload($matter, $this->pdf(), 'Other', null, null, $otherContact);
            $this->fail('A client uploaded to another client\'s matter.');
        } catch (AuthorizationException) {
        }
    }

    public function test_upload_guard_rejects_mismatched_and_macro_files(): void
    {
        [$matter] = $this->openMatter();
        $docs = app(Documents::class);
        $meta = ['title' => 'Bad', 'category' => 'evidence'];

        $cases = [
            'fake.pdf' => UploadedFile::fake()->createWithContent('fake.pdf', "MZ\x90\x00 not a pdf"),
            'script.php' => UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;'),
            'macro.docx' => $this->docx(true),
        ];
        foreach ($cases as $name => $file) {
            try {
                $docs->upload($matter, $file, $meta, $this->lawyer);
                $this->fail("Accepted {$name}");
            } catch (RuleViolation) {
            }
        }

        $ok = $docs->upload($matter, $this->docx(false), ['title' => 'Letter', 'category' => 'correspondence'], $this->lawyer);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $ok->currentVersion->mime_type);
        $this->assertSame(1, Document::count());
        $this->assertCount(1, Storage::disk('confidential')->allFiles());
    }

    public function test_enquiry_documents_are_limited_to_the_owner_and_move_into_the_matter(): void
    {
        $enquiry = $this->readyEnquiry();
        $doc = app(Documents::class)->upload($enquiry, $this->pdf(), ['title' => 'ID', 'category' => 'identity'], $this->lawyer);
        $this->assertNull($doc->matter_id);
        $this->assertTrue($this->lawyer->can('view', $doc));
        $this->assertFalse($this->userWithRoles(Role::Lawyer)->can('view', $doc));

        $contact = $this->clientContact($enquiry->client);
        $engagements = app(\App\Domain\Engagement\Engagements::class);
        $engagement = $engagements->create($enquiry, $this->template(), 'Terms', null, $this->lawyer);
        $engagements->send($engagement, $this->lawyer);
        $engagements->respond($engagement->refresh(), $engagement->current_version_id, 'accepted', 'Ada Obi', null, $contact, null, null);
        $matter = $engagements->approve($engagement->refresh(), $this->lawyer, null, $this->admin);

        $this->assertSame($matter->id, $doc->refresh()->matter_id);
        $this->assertFalse($contact->can('viewAsClient', $doc), 'Internal intake files are not shown to the client.');
    }

    private function docx(bool $withMacro): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        if ($withMacro) {
            $zip->addFromString('word/vbaProject.bin', 'binary');
        }
        $zip->close();

        return new UploadedFile($path, $withMacro ? 'macro.docx' : 'letter.docx', null, null, true);
    }
}
