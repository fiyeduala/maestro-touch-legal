<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Invoices;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\TimeEntries;
use App\Domain\Identity\Role;
use App\Domain\Reporting\Reports as ReportData;
use App\Domain\RuleViolation;
use App\Filament\Pages\Reports;
use App\Filament\Resources\BillingRates\Pages\ListBillingRates;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Matters\Pages\ViewMatter;
use App\Filament\Resources\Matters\RelationManagers\TimeRelationManager;
use App\Filament\Resources\TimeEntries\Pages\ListTimeEntries;
use App\Models\AuditEvent;
use App\Models\BillingRate;
use App\Models\Invoice;
use App\Models\TimeEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BillingFixtures;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** D53: time recorded on matters and billed on draft invoices at hourly rates. */
class TimeRecordingTest extends TestCase
{
    use BillingFixtures;
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        Mail::fake();
        Notification::fake();
        $this->setUpPractice();
        $this->setUpBilling();
    }

    private function time(): TimeEntries
    {
        return app(TimeEntries::class);
    }

    private function entry($matter, int $minutes = 90, ?string $day = null, $who = null): TimeEntry
    {
        return $this->time()->record($matter, [
            'worked_on' => $day ?? now(config('app.firm_timezone'))->toDateString(), 'minutes' => $minutes, 'description' => 'Reviewed the lease',
        ], $who ?? $this->lawyer);
    }

    public function test_team_members_record_their_own_time_within_the_rules(): void
    {
        [$matter] = $this->openMatter();
        $entry = $this->entry($matter);
        $this->assertSame([$this->lawyer->id, $matter->client_id, 90, true], [$entry->user_id, $entry->client_id, $entry->minutes, $entry->billable]);
        $this->assertTrue(AuditEvent::where('action', 'time.recorded')->exists());
        $this->assertSame('1h 30m', TimeEntry::duration(90));
        $this->assertSame('45m', TimeEntry::duration(45));
        $this->assertSame('2h', TimeEntry::duration(120));

        $bad = fn (array $data) => $this->assertThrows(fn () => $this->time()->record($matter, $data + [
            'worked_on' => now()->toDateString(), 'minutes' => 30, 'description' => 'Work',
        ], $this->lawyer), RuleViolation::class);
        $bad(['minutes' => 0]);
        $bad(['minutes' => 1441]);
        $bad(['description' => '  ']);
        $bad(['worked_on' => now(config('app.firm_timezone'))->addDays(2)->toDateString()]);

        // Not on the team, finance and a content editor cannot record time on the matter.
        foreach ([$this->userWithRoles(Role::Lawyer), $this->finance, $this->userWithRoles(Role::ContentEditor)] as $outsider) {
            $this->assertThrows(fn () => $this->entry($matter, 30, null, $outsider), AuthorizationException::class);
        }
        $this->assertSame(30, $this->entry($matter, 30, null, $this->admin)->minutes);
    }

    public function test_a_timer_runs_one_at_a_time_and_rounds_up_to_the_minute(): void
    {
        [$matter] = $this->openMatter();
        $this->travelTo(now()->setTime(10, 0));
        $timer = $this->time()->startTimer($matter, $this->lawyer);
        $this->assertTrue($timer->isRunning());
        $this->assertThrows(fn () => $this->time()->startTimer($matter, $this->lawyer), RuleViolation::class);
        $this->assertFalse($this->lawyer->can('update', $timer));
        $this->assertFalse($this->admin->can('stop', $timer));

        $this->travel(61)->seconds();
        $stopped = $this->time()->stopTimer($timer, 'Call with client', true, $this->lawyer);
        $this->assertSame(2, $stopped->minutes);
        $this->assertNull($stopped->started_at);
        $this->assertThrows(fn () => $this->time()->stopTimer($stopped, 'Again', true, $this->lawyer), AuthorizationException::class);

        $long = $this->time()->startTimer($matter, $this->lawyer);
        $this->travel(25)->hours();
        $this->assertThrows(fn () => $this->time()->stopTimer($long, 'Forgot', true, $this->lawyer), RuleViolation::class);
        $this->time()->discardTimer($long, $this->lawyer);
        $this->assertModelMissing($long);
        $this->assertTrue(AuditEvent::where('action', 'time.timer_discarded')->exists());
    }

    public function test_rates_prefer_the_matter_rate_and_are_finance_only(): void
    {
        [$matter] = $this->openMatter();
        $this->assertThrows(fn () => $this->time()->setRate($this->lawyer, null, 'NGN', 5_000_000, $this->lawyer), AuthorizationException::class);
        $this->assertThrows(fn () => $this->time()->setRate($this->finance, null, 'NGN', 5_000_000, $this->finance), RuleViolation::class);
        $this->assertThrows(fn () => $this->time()->setRate($this->lawyer, null, 'NGN', 0, $this->finance), RuleViolation::class);

        $this->time()->setRate($this->lawyer, null, 'NGN', 5_000_000, $this->finance);
        $this->assertSame(5_000_000, TimeEntries::rateFor($this->lawyer->id, $matter->id, 'NGN'));
        $this->time()->setRate($this->lawyer, $matter, 'NGN', 4_000_000, $this->finance);
        $this->assertSame(4_000_000, TimeEntries::rateFor($this->lawyer->id, $matter->id, 'NGN'));
        $this->assertNull(TimeEntries::rateFor($this->lawyer->id, $matter->id, 'USD'));

        $this->time()->setRate($this->lawyer, null, 'NGN', 6_000_000, $this->finance); // replaces the default
        $this->assertSame(2, BillingRate::count());
        $this->assertSame(3, AuditEvent::where('action', 'rate.set')->count());
    }

    public function test_time_is_billed_once_at_the_rate_and_released_when_the_draft_changes(): void
    {
        [$matter] = $this->openMatter();
        $entry = $this->entry($matter, 90);
        $other = $this->entry($matter, 20);
        $this->assertThrows(fn () => $this->invoices()->createDraft($matter->client, ['title' => 'Fees', 'currency' => 'NGN', 'time_entry_ids' => [$entry->id]], $this->finance), RuleViolation::class);

        $this->time()->setRate($this->lawyer, null, 'NGN', 5_000_000, $this->finance); // ₦50,000/h
        $invoice = $this->invoices()->createDraft($matter->client, ['title' => 'Fees', 'currency' => 'NGN', 'time_entry_ids' => [$entry->id, $other->id]], $this->finance);
        $this->assertSame(7_500_000 + 1_666_667, $invoice->fees_minor);
        $this->assertSame([$invoice->id, 5_000_000, 7_500_000], [$entry->refresh()->invoice_id, $entry->billed_rate_minor, $entry->billed_minor]);
        $line = $invoice->lines()->where('time_entry_id', $entry->id)->first();
        $this->assertStringContainsString('1h 30m at ₦50,000.00/h: Reviewed the lease', $line->description);
        $this->assertSame('On draft '.$invoice->reference, $entry->load('invoice')->statusLabel());

        // On a draft the entry is fixed and cannot go on a second invoice.
        $this->assertFalse($this->lawyer->can('update', $entry));
        $this->assertThrows(fn () => $this->invoices()->createDraft($matter->client, ['title' => 'Again', 'currency' => 'NGN', 'time_entry_ids' => [$entry->id]], $this->finance), RuleViolation::class);

        // The edit form keeps time out of the manual lines; dropping an entry releases it.
        $form = Invoices::toForm($invoice);
        $this->assertSame([], $form['lines']);
        $this->assertEqualsCanonicalizing([(string) $entry->id, (string) $other->id], $form['time_entry_ids']);
        $this->invoices()->updateDraft($invoice, ['title' => 'Fees', 'time_entry_ids' => [$entry->id]], $this->finance);
        $this->assertNull($other->refresh()->invoice_id);
        $this->assertNull($other->billed_minor);

        $this->invoices()->discardDraft($invoice->refresh(), 'Wrong client', $this->finance);
        $this->assertNull($entry->refresh()->invoice_id);
        $this->assertTrue($entry->isOpen());

        // Issued: billed for good, at the rate of the day.
        $issued = $this->invoices()->createDraft($matter->client, ['title' => 'Fees', 'currency' => 'NGN', 'time_entry_ids' => [$entry->id]], $this->finance);
        $this->invoices()->issue($issued, $this->finance);
        $this->time()->setRate($this->lawyer, null, 'NGN', 9_000_000, $this->finance);
        $this->assertSame(7_500_000, $entry->refresh()->billed_minor);
        $this->assertSame('Billed on '.$issued->reference, $entry->load('invoice')->statusLabel());
        $this->assertFalse($this->finance->can('void', $entry));
    }

    public function test_voided_running_and_non_billable_time_never_reaches_an_invoice(): void
    {
        [$matter] = $this->openMatter();
        $this->time()->setRate($this->lawyer, null, 'NGN', 5_000_000, $this->finance);
        $voided = $this->entry($matter);
        $this->assertThrows(fn () => $this->time()->void($voided, 'no', $this->lawyer), RuleViolation::class);
        $this->time()->void($voided, 'Entered twice', $this->lawyer);
        $free = $this->time()->record($matter, ['worked_on' => now()->toDateString(), 'minutes' => 30, 'description' => 'Courtesy call', 'billable' => false], $this->lawyer);
        $running = $this->time()->startTimer($matter, $this->lawyer);
        [$otherMatter] = $this->openMatter();
        $foreign = $this->entry($otherMatter);

        foreach ([$voided, $free, $running, $foreign] as $entry) {
            $this->assertThrows(fn () => $this->invoices()->createDraft($matter->client, ['title' => 'Fees', 'currency' => 'NGN', 'time_entry_ids' => [$entry->id]], $this->finance), RuleViolation::class);
        }
        $this->assertSame([], TimeEntries::billableOptions($matter->client_id, 'NGN'));
    }

    public function test_corrections_are_audited_and_only_owners_or_finance_may_make_them(): void
    {
        [$matter] = $this->openMatter();
        $entry = $this->entry($matter, 60);
        $colleague = $this->teamMember($matter, Role::Lawyer);
        $this->assertFalse($colleague->can('update', $entry));
        $this->assertTrue($colleague->can('view', $entry));

        $this->time()->update($entry, ['worked_on' => $entry->worked_on->toDateString(), 'minutes' => 75, 'description' => 'Reviewed and marked up the lease'], $this->finance);
        $this->assertSame(75, $entry->refresh()->minutes);
        $audit = AuditEvent::where('action', 'time.updated')->firstOrFail();
        $this->assertSame(60, $audit->changes['before']['minutes']);
        $this->assertFalse($this->lawyer->can('delete', $entry));
    }

    public function test_bulk_draft_needs_one_client_and_creates_the_invoice(): void
    {
        [$matter] = $this->openMatter();
        [$otherMatter] = $this->openMatter();
        $this->time()->setRate($this->lawyer, null, 'NGN', 6_000_000, $this->finance);
        $a = $this->entry($matter, 60);
        $b = $this->entry($otherMatter, 60);
        $this->assertThrows(fn () => $this->time()->draftInvoice(TimeEntry::whereIn('id', [$a->id, $b->id])->get(), 'NGN', $this->finance), RuleViolation::class);
        $this->assertThrows(fn () => $this->time()->draftInvoice(TimeEntry::whereKey($a->id)->get(), 'NGN', $this->lawyer), AuthorizationException::class);

        $c = $this->entry($matter, 30);
        $invoice = $this->time()->draftInvoice(TimeEntry::whereIn('id', [$a->id, $c->id])->get(), 'NGN', $this->finance);
        $this->assertSame([InvoiceStatus::Draft, $matter->id, 9_000_000], [$invoice->status, $invoice->matter_id, $invoice->total_minor]);
    }

    public function test_matter_tab_time_list_and_rates_screens(): void
    {
        [$matter] = $this->openMatter();
        $this->time()->setRate($this->lawyer, null, 'NGN', 5_000_000, $this->finance);

        $this->actingAsStaff($this->lawyer);
        Livewire::test(TimeRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('record', data: ['worked_on' => now(config('app.firm_timezone'))->toDateString(), 'hours' => 1, 'mins' => 15, 'description' => 'Drafted the deed', 'billable' => true])
            ->assertHasNoTableActionErrors()
            ->callTableAction('startTimer')
            ->assertSee('Drafted the deed')->assertSee('1h 15m')->assertSee('Timer running');
        $this->assertSame(75, TimeEntry::whereNotNull('minutes')->value('minutes'));
        $this->assertTrue(TimeEntry::running()->exists());

        Livewire::test(ListTimeEntries::class)->assertCanSeeTableRecords(TimeEntry::all())->assertTableBulkActionHidden('draftInvoice');
        $this->get('/admin/billing-rates')->assertForbidden();

        $outsider = $this->userWithRoles(Role::Lawyer);
        $this->actingAsStaff($outsider);
        Livewire::test(ListTimeEntries::class)->assertCanNotSeeTableRecords(TimeEntry::all());

        $this->actingAsStaff($this->finance);
        $finished = TimeEntry::whereNotNull('minutes')->get();
        Livewire::test(ListTimeEntries::class)
            ->callTableBulkAction('draftInvoice', $finished, data: ['currency' => 'NGN'])
            ->assertHasNoTableBulkActionErrors();
        $invoice = Invoice::latest('id')->firstOrFail();
        $this->assertSame(6_250_000, $invoice->total_minor);
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertSee('1h 15m at ₦50,000.00/h: Drafted the deed');

        Livewire::test(ListBillingRates::class)
            ->assertSee('₦50,000.00')
            ->callAction('set', data: ['user_id' => $this->lawyer->id, 'currency' => 'USD', 'rate' => '150'])
            ->assertHasNoActionErrors();
        $this->assertSame(15_000, TimeEntries::rateFor($this->lawyer->id, $matter->id, 'USD'));
    }

    public function test_new_invoice_from_a_matter_ticks_its_unbilled_time(): void
    {
        [$matter] = $this->openMatter();
        $entry = $this->entry($matter, 30);
        $this->actingAsStaff($this->finance);
        Livewire::withQueryParams(['matter' => $matter->id])->test(CreateInvoice::class)
            ->assertSet('data.time_entry_ids', [(string) $entry->id])
            ->assertSet('data.lines', []);
    }

    public function test_time_report_by_person_and_billed_amounts_per_currency(): void
    {
        [$matter] = $this->openMatter();
        $this->time()->setRate($this->lawyer, null, 'NGN', 5_000_000, $this->finance);
        $billed = $this->entry($matter, 60);
        $this->entry($matter, 30);
        $this->time()->record($matter, ['worked_on' => now()->toDateString(), 'minutes' => 15, 'description' => 'Admin', 'billable' => false], $this->lawyer);
        $invoice = $this->invoices()->createDraft($matter->client, ['title' => 'Fees', 'currency' => 'NGN', 'time_entry_ids' => [$billed->id]], $this->finance);
        $this->invoices()->issue($invoice, $this->finance);

        $report = app(ReportData::class)->time([]);
        $this->assertSame([['name' => $this->lawyer->name, 'recorded' => 105, 'billable' => 90, 'billed' => 60, 'unbilled' => 30]], $report['people']);
        $this->assertSame(['NGN' => 5_000_000, 'USD' => 0], $report['billed']);
        $this->assertSame([], app(ReportData::class)->time(['team_user_id' => $this->admin->id])['people']);

        $this->actingAsStaff($this->finance);
        Livewire::test(Reports::class)->assertSee('Hours by staff member')->assertSee('1h 45m');
    }
}
