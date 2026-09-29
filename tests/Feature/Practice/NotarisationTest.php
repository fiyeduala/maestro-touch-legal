<?php

namespace Tests\Feature\Practice;

use App\Domain\Identity\Role;
use App\Domain\Notarisation\NotarisationHandoffs;
use App\Domain\RuleViolation;
use App\Filament\Resources\Matters\Pages\ViewMatter;
use App\Filament\Resources\Matters\RelationManagers\NotarisationRelationManager;
use App\Models\MatterEvent;
use App\Models\NotarisationHandoff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\PracticeFixtures;
use Tests\TestCase;

/** Manual Naija Virtual Notary handoffs: consent first, tracked by hand, nothing sent anywhere (D40). */
class NotarisationTest extends TestCase
{
    use PracticeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('confidential');
        Http::preventStrayRequests();
        $this->setUpPractice();
    }

    public function test_consent_is_recorded_before_anything_else(): void
    {
        [$matter] = $this->openMatter();
        $handoffs = app(NotarisationHandoffs::class);

        $this->assertThrows(fn () => $handoffs->record($matter, ['document_description' => 'Affidavit of support', 'consent_method' => 'email'], $this->lawyer),
            RuleViolation::class, 'consent');

        $handoff = $handoffs->record($matter, ['document_description' => 'Affidavit of support', 'consent_method' => 'email',
            'consent_note' => 'Email of 3 Oct', 'consent_confirmed' => true], $this->lawyer);
        $this->assertSame('consented', $handoff->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'notarisation.consent_recorded']);
        $this->assertTrue(MatterEvent::where('matter_id', $matter->id)->where('type', 'notarisation')->where('client_visible', true)->exists());

        // Staff outside the matter cannot record one.
        $outsider = $this->userWithRoles(Role::Lawyer);
        $this->assertThrows(fn () => $handoffs->record($matter, ['document_description' => 'X', 'consent_method' => 'email', 'consent_confirmed' => true], $outsider),
            AuthorizationException::class);
    }

    public function test_status_moves_forward_and_notarised_needs_the_nvn_reference(): void
    {
        [$matter] = $this->openMatter();
        $handoffs = app(NotarisationHandoffs::class);
        $handoff = $handoffs->record($matter, ['document_description' => 'Deed of assignment', 'consent_method' => 'written', 'consent_confirmed' => true], $this->lawyer);

        $this->assertThrows(fn () => $handoffs->updateStatus($handoff, 'completed', null, null, $this->lawyer), RuleViolation::class);
        $handoffs->updateStatus($handoff, 'sent', null, 'Uploaded by the client on NVN', $this->lawyer);
        $this->assertThrows(fn () => $handoffs->updateStatus($handoff->refresh(), 'completed', null, null, $this->lawyer), RuleViolation::class, 'reference');
        $this->assertThrows(fn () => $handoffs->updateStatus($handoff, 'completed', '<script>', null, $this->lawyer), RuleViolation::class);
        $handoffs->updateStatus($handoff, 'completed', 'NVN-2026-00123', null, $this->lawyer);

        $handoff->refresh();
        $this->assertSame('completed', $handoff->status);
        $this->assertSame('NVN-2026-00123', $handoff->external_reference);
        $this->assertTrue($handoff->isFinal());
        $this->assertThrows(fn () => $handoffs->updateStatus($handoff, 'cancelled', null, 'Too late', $this->lawyer), RuleViolation::class);

        // Internal notes stay off the client timeline.
        $this->assertFalse(MatterEvent::where('matter_id', $matter->id)->where('summary', 'like', '%Uploaded by the client%')->exists());
    }

    public function test_the_matter_section_records_and_updates_a_handoff(): void
    {
        [$matter] = $this->openMatter();
        $this->actingAsStaff($this->lawyer);
        $section = fn () => Livewire::test(NotarisationRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);

        $section()->assertOk()->assertSee('No notarisation arranged')
            ->callTableAction('record', data: ['document_description' => 'Power of attorney', 'consent_method' => 'portal'])
            ->assertHasTableActionErrors(['consent_confirmed']);

        $section()->callTableAction('record', data: ['document_description' => 'Power of attorney', 'consent_method' => 'portal', 'consent_confirmed' => true])
            ->assertHasNoTableActionErrors();
        $handoff = NotarisationHandoff::sole();

        $section()->assertSee('Power of attorney')
            ->callTableAction('status', $handoff, data: ['status' => 'cancelled', 'external_reference' => null, 'note' => 'Client withdrew'])
            ->assertNotified('Notarisation updated');
        $this->assertSame('cancelled', $handoff->refresh()->status);
    }
}
