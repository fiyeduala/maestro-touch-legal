<?php

namespace App\Domain\Intake;

use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\Operations\StaffNotifier;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enquiry;
use App\Models\EnquiryEvent;
use App\Models\IntakeForm;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Intake\EnquiryAcknowledgement;
use App\Notifications\StaffAlert;
use App\Support\References;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Enquiry pipeline (spec §6). An enquiry never creates representation; that happens only when
 * engagement terms are accepted and internally approved (Engagement\Engagements::approve).
 */
class Enquiries
{
    public const CONSENT_VERSION = '2026-09-draft';

    /**
     * Validation rules for a published intake form's answers, keyed answers.{field}.
     *
     * @return array<string, list<mixed>>
     */
    public static function answerRules(?IntakeForm $form): array
    {
        $rules = [];
        foreach ($form?->fields ?? [] as $field) {
            $key = 'answers.'.$field['key'];
            $base = ! empty($field['required']) ? ['required'] : ['nullable'];
            $rules[$key] = match ($field['type']) {
                'textarea' => [...$base, 'string', 'max:5000'],
                'date' => [...$base, 'date'],
                'number' => [...$base, 'numeric', 'min:0', 'max:999999999999'],
                'select' => [...$base, 'string', Rule::in($field['options'] ?? [])],
                default => [...$base, 'string', 'max:500'],
            };
        }

        return $rules;
    }

    /**
     * Submission from the public website (or a signed-in client). Contact details are unverified.
     *
     * @param  array{contact_name: string, contact_email: string, contact_phone?: ?string, organisation_name?: ?string, country?: ?string, summary: string, preferred_times?: ?string, answers?: array<string, mixed>}  $data
     */
    public function submitPublic(array $data, ?Service $service, EnquirySource $source, ?User $submitter = null, ?string $ip = null): Enquiry
    {
        $form = $service?->publishedForm;

        $enquiry = DB::transaction(function () use ($data, $service, $form, $source, $submitter, $ip) {
            $client = $submitter?->isClient() ? $submitter->clients()->wherePivot('relationship', 'owner')->first() : null;

            $enquiry = Enquiry::create([
                'reference' => References::next('enquiries', 'ENQ'),
                'status' => EnquiryStatus::New,
                'source' => $source,
                'service_id' => $service?->id,
                'intake_form_id' => $form?->id,
                'answers' => $this->snapshot($form, $data['answers'] ?? []),
                'summary' => trim($data['summary']),
                'preferred_times' => $data['preferred_times'] ?? null,
                'contact_name' => trim($data['contact_name']),
                'contact_email' => Str::lower(trim($data['contact_email'])),
                'contact_phone' => $data['contact_phone'] ?? null,
                'organisation_name' => $data['organisation_name'] ?? null,
                'country' => $data['country'] ?? null,
                'client_id' => $client?->id,
                'submitted_by' => $submitter?->id,
                'consent_at' => now(),
                'consent_version' => self::CONSENT_VERSION,
                'submitted_ip' => $ip,
            ]);

            $this->addParty($enquiry, $enquiry->organisation_name ?: $enquiry->contact_name, 'client', null, null, false);
            if ($enquiry->organisation_name) {
                $this->addParty($enquiry, $enquiry->contact_name, 'related', 'Contact person', null, false);
            }
            $this->event($enquiry, 'submitted', null, EnquiryStatus::New, null, $submitter);
            Audit::record('enquiry.submitted', "Enquiry {$enquiry->reference} received ({$source->label()})", $enquiry, actor: $submitter);

            return $enquiry;
        });

        Notification::route('mail', [$enquiry->contact_email => $enquiry->contact_name])
            ->notify(new EnquiryAcknowledgement($enquiry));
        StaffNotifier::administrators(new StaffAlert(
            "New enquiry – {$enquiry->reference}",
            'A new enquiry'.($service ? " about {$service->name}" : '').' is waiting for triage.',
            "/admin/enquiries/{$enquiry->id}",
        ), 'Enquiry');

        return $enquiry;
    }

    /**
     * An enquiry received by phone, email, WhatsApp or in person, entered by staff.
     * A non-administrator who records it becomes its owner so they can continue triage.
     */
    public function createByStaff(array $data, User $actor): Enquiry
    {
        Gate::forUser($actor)->authorize('create', Enquiry::class);
        $source = EnquirySource::from($data['source']);
        if (! array_key_exists($source->value, EnquirySource::staffOptions())) {
            throw new RuleViolation('Choose how the enquiry was received.');
        }
        $service = isset($data['service_id']) ? Service::find($data['service_id']) : null;

        return DB::transaction(function () use ($data, $actor, $source, $service) {
            $enquiry = Enquiry::create([
                'reference' => References::next('enquiries', 'ENQ'),
                'status' => EnquiryStatus::Triage,
                'source' => $source,
                'service_id' => $service?->id,
                'summary' => trim($data['summary']),
                'preferred_times' => $data['preferred_times'] ?? null,
                'contact_name' => trim($data['contact_name']),
                'contact_email' => Str::lower(trim($data['contact_email'])),
                'contact_phone' => $data['contact_phone'] ?? null,
                'organisation_name' => $data['organisation_name'] ?? null,
                'country' => $data['country'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'created_by' => $actor->id,
                'owner_id' => $actor->isFullAdministrator() ? ($data['owner_id'] ?? null) : $actor->id,
            ]);
            $this->addParty($enquiry, $enquiry->organisation_name ?: $enquiry->contact_name, 'client', null, $actor, false);
            $this->event($enquiry, 'recorded', null, EnquiryStatus::Triage, "Recorded by staff ({$source->label()})", $actor);
            Audit::record('enquiry.recorded', "Enquiry {$enquiry->reference} recorded by staff ({$source->label()})", $enquiry, actor: $actor);

            return $enquiry;
        });
    }

    public function transition(Enquiry $enquiry, EnquiryStatus $to, User $actor, ?string $reason = null): void
    {
        Gate::forUser($actor)->authorize('update', $enquiry);
        $reason = $reason !== null ? trim($reason) : null;

        DB::transaction(function () use ($enquiry, $to, $actor, $reason) {
            $enquiry = Enquiry::lockForUpdate()->findOrFail($enquiry->id);
            $from = $enquiry->status;
            if (! $from->canTransitionTo($to)) {
                throw new RuleViolation("An enquiry cannot move from {$from->label()} to {$to->label()}.");
            }
            if (in_array($to, [EnquiryStatus::Declined, EnquiryStatus::Closed], true) && ($reason === null || mb_strlen($reason) < 5)) {
                throw new RuleViolation('Record the reason for closing or declining this enquiry.');
            }

            $closing = ! $to->isOpen();
            $enquiry->forceFill([
                'status' => $to,
                'closure_reason' => $closing ? $reason : $enquiry->closure_reason,
                'closed_at' => $closing ? now() : null,
            ])->save();

            $this->event($enquiry, 'status_changed', $from, $to, $reason, $actor);
            Audit::record('enquiry.status_changed', "{$enquiry->reference}: {$from->label()} → {$to->label()}", $enquiry,
                ['before' => ['status' => $from->value], 'after' => ['status' => $to->value]], actor: $actor);
        });
    }

    /** Pipeline moves made by other workflows (quotation sent, engagement sent); only ever forwards. */
    public function advance(Enquiry $enquiry, EnquiryStatus $to, ?User $actor, string $note): void
    {
        $order = array_flip(array_map(fn ($c) => $c->value, EnquiryStatus::cases()));
        if (! $enquiry->status->isOpen() || $order[$enquiry->status->value] >= $order[$to->value]) {
            return;
        }
        $from = $enquiry->status;
        $enquiry->forceFill(['status' => $to])->save();
        $this->event($enquiry, 'status_changed', $from, $to, $note, $actor);
    }

    public function assign(Enquiry $enquiry, ?User $owner, User $actor): void
    {
        Gate::forUser($actor)->authorize('assign', $enquiry);
        if ($owner && ! ($owner->isActive() && ($owner->isFullAdministrator() || $owner->hasRole(Role::Lawyer, Role::CaseOfficer)))) {
            throw new RuleViolation('An enquiry can only be assigned to an active lawyer, case officer or administrator.');
        }

        $before = $enquiry->owner_id;
        $enquiry->forceFill(['owner_id' => $owner?->id])->save();
        $this->event($enquiry, 'assigned', null, null, 'Owner: '.($owner?->name ?? 'nobody'), $actor);
        Audit::record('enquiry.assigned', "{$enquiry->reference} assigned to ".($owner?->name ?? 'nobody'), $enquiry,
            ['before' => ['owner_id' => $before], 'after' => ['owner_id' => $owner?->id]], actor: $actor);

        if ($owner && $owner->id !== $actor->id) {
            StaffNotifier::users([$owner], new StaffAlert("Enquiry assigned to you – {$enquiry->reference}",
                'An enquiry has been assigned to you for triage.', "/admin/enquiries/{$enquiry->id}"));
        }
    }

    public function setFollowUp(Enquiry $enquiry, ?string $date, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $enquiry);
        $enquiry->forceFill(['follow_up_on' => $date])->save();
        $this->event($enquiry, 'follow_up_set', null, null, $date ? "Follow up on {$date}" : 'Follow-up date cleared', $actor);
    }

    public function addNote(Enquiry $enquiry, string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $enquiry);
        $this->event($enquiry, 'note', null, null, trim($note), $actor);
    }

    /**
     * Adding a party after a conflict decision reopens the check: the decision no longer covers everyone.
     */
    public function addParty(Enquiry $enquiry, string $name, string $role, ?string $notes, ?User $actor, bool $authorise = true): Party
    {
        if ($authorise) {
            Gate::forUser($actor)->authorize('update', $enquiry);
        }
        if (! array_key_exists($role, Party::ROLES)) {
            throw new RuleViolation('Choose a valid party role.');
        }
        if (! $enquiry->status->isOpen()) {
            throw new RuleViolation('Parties can only be added while the enquiry is open.');
        }

        $party = $enquiry->parties()->create([
            'name' => trim($name),
            'role' => $role,
            'notes' => $notes,
            'added_by' => $actor?->id,
        ]);

        if ($authorise) {
            $this->event($enquiry, 'party_added', null, null, Party::ROLES[$role].': '.$party->name, $actor);
            if ($enquiry->conflict_status !== 'pending') {
                $enquiry->forceFill(['conflict_status' => 'pending', 'conflict_reviewed_at' => null])->save();
                $this->event($enquiry, 'conflict_reset', null, null, 'Conflict check reopened because a party was added.', $actor);
                Audit::record('enquiry.conflict_reset', "{$enquiry->reference}: conflict check reopened after a party was added", $enquiry, actor: $actor);
            }
        }

        return $party;
    }

    public function removeParty(Party $party, User $actor): void
    {
        $enquiry = $party->enquiry;
        Gate::forUser($actor)->authorize('update', $enquiry);
        if ($enquiry->conflict_status !== 'pending') {
            throw new RuleViolation('Parties cannot be removed after a conflict decision; add a note instead.');
        }
        $this->event($enquiry, 'party_removed', null, null, $party->name, $actor);
        $party->delete();
    }

    /** Links an existing client record (for example an existing client's new request). */
    public function linkClient(Enquiry $enquiry, Client $client, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $enquiry);
        if ($enquiry->client_id && $enquiry->client_id !== $client->id && $enquiry->quotations()->exists()) {
            throw new RuleViolation('This enquiry already has quotations for another client.');
        }
        $enquiry->forceFill(['client_id' => $client->id])->save();
        $this->event($enquiry, 'client_linked', null, null, "Linked to client {$client->reference}", $actor);
        Audit::record('enquiry.client_linked', "{$enquiry->reference} linked to client {$client->reference}", $enquiry, actor: $actor);
    }

    /**
     * Spam only (D48): removes an enquiry that never became work, with its history, parties and conflict notes.
     * Anything linked to a client, quotation, terms, consultation, matter or file stays and must be closed instead.
     * The audit log keeps the reference and who deleted it.
     */
    public function deleteAsSpam(Enquiry $enquiry, User $actor): void
    {
        if (! $actor->isFullAdministrator()) {
            throw new RuleViolation('Only a full administrator can delete enquiries.');
        }
        if ($enquiry->client_id || $enquiry->matter_id || $enquiry->quotations()->exists() || $enquiry->engagements()->exists()
            || $enquiry->documents()->exists() || Consultation::where('enquiry_id', $enquiry->id)->exists()
            || Matter::where('enquiry_id', $enquiry->id)->exists()) {
            throw new RuleViolation("{$enquiry->reference} is linked to a client, quotation, consultation, matter or file. Close it instead.");
        }

        DB::transaction(function () use ($enquiry, $actor) {
            Audit::record('enquiry.deleted_as_spam', "Enquiry {$enquiry->reference} deleted as spam", $enquiry,
                context: ['source' => $enquiry->source->value], actor: $actor);
            $enquiry->parties()->whereNull('matter_id')->delete();
            $enquiry->delete();
        });
    }

    /** Creates the client record from the enquiry's contact details (no portal account is created). */
    public function createClient(Enquiry $enquiry, User $actor, string $timezone = 'Africa/Lagos', string $currency = 'NGN'): Client
    {
        Gate::forUser($actor)->authorize('update', $enquiry);
        if ($enquiry->client_id) {
            throw new RuleViolation('This enquiry is already linked to a client.');
        }

        return DB::transaction(function () use ($enquiry, $actor, $timezone, $currency) {
            $organisation = filled($enquiry->organisation_name);
            $client = Client::create([
                'type' => $organisation ? 'organisation' : 'individual',
                'display_name' => $organisation ? $enquiry->organisation_name : $enquiry->contact_name,
                'organisation_name' => $enquiry->organisation_name,
                'email' => $enquiry->contact_email,
                'phone' => $enquiry->contact_phone,
                'country' => $enquiry->country,
                'timezone' => $timezone,
                'preferred_currency' => $currency,
                'relationship_owner_id' => $enquiry->owner_id,
                'created_by' => $actor->id,
            ]);
            Audit::record('client.created', "Client {$client->reference} created from enquiry {$enquiry->reference}", $client, actor: $actor);
            $this->linkClient($enquiry, $client, $actor);

            return $client;
        });
    }

    public function event(Enquiry $enquiry, string $type, ?EnquiryStatus $from, ?EnquiryStatus $to, ?string $body, ?User $actor): EnquiryEvent
    {
        return EnquiryEvent::create([
            'enquiry_id' => $enquiry->id,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'body' => $body,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /** @return list<array{key: string, label: string, value: mixed}> */
    private function snapshot(?IntakeForm $form, array $answers): array
    {
        $out = [];
        foreach ($form?->fields ?? [] as $field) {
            $value = $answers[$field['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $out[] = ['key' => $field['key'], 'label' => $field['label'], 'value' => is_string($value) ? trim($value) : $value];
        }

        return $out;
    }
}
