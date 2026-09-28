<?php

namespace App\Domain\Matters;

use App\Domain\Clients\ClientContacts;
use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Engagement;
use App\Models\Matter;
use App\Models\MatterDeadline;
use App\Models\MatterEvent;
use App\Models\MatterTeamMember;
use App\Models\Party;
use App\Models\Service;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Support\References;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Matter lifecycle (spec §7). A matter opens only from an approved engagement; team membership is the
 * only route to matter access for lawyers and case officers, so every change to it is recorded.
 */
class Matters
{
    public const EDITABLE = ['title', 'client_summary', 'internal_assessment', 'jurisdiction', 'priority', 'confidentiality',
        'next_action', 'next_action_due_at', 'next_action_client_visible'];

    public function __construct(private ClientContacts $contacts) {}

    /** Called only by Engagements::approve, inside its transaction. */
    public function open(Engagement $engagement, User $responsible, User $actor): Matter
    {
        $this->assertCanJoin($responsible, 'responsible');
        $service = $engagement->enquiry?->service;
        $stages = $service?->stageList() ?? Service::DEFAULT_STAGES;

        $matter = Matter::create([
            'reference' => References::next('matters', 'MAT'),
            'client_id' => $engagement->client_id,
            'service_id' => $service?->id,
            'enquiry_id' => $engagement->enquiry_id,
            'engagement_id' => $engagement->id,
            'title' => $engagement->title,
            'stage' => $stages[0]['key'],
            'status' => MatterStatus::Open,
            'opened_at' => now(),
            'representation_started_at' => now(),
            'created_by' => $actor->id,
        ]);

        $this->join($matter, $responsible, 'responsible', $actor);
        $owner = $engagement->enquiry?->owner;
        if ($owner && $owner->id !== $responsible->id && $owner->isActive() && $owner->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            $this->join($matter, $owner, 'member', $actor);
        }

        $this->event($matter, 'opened', 'Matter opened. The firm now acts for you on this matter.', true, $actor);
        Audit::record('matter.opened', "Matter {$matter->reference} opened from engagement {$engagement->reference}; responsible lawyer {$responsible->email}", $matter, actor: $actor);

        return $matter;
    }

    public function addTeamMember(Matter $matter, User $user, User $actor): MatterTeamMember
    {
        Gate::forUser($actor)->authorize('manageTeam', $matter);
        $this->assertCanJoin($user, 'member');
        if ($matter->isOnTeam($user)) {
            throw new RuleViolation("{$user->name} is already on this matter's team.");
        }

        return DB::transaction(function () use ($matter, $user, $actor) {
            $member = $this->join($matter, $user, 'member', $actor);
            $this->event($matter, 'team_added', "{$user->name} joined the team.", false, $actor, ['user_id' => $user->id]);
            Audit::record('matter.team_added', "{$user->email} added to {$matter->reference}", $matter, actor: $actor);
            $user->notify(new StaffAlert("You have been added to matter {$matter->reference}", "You now have access to {$matter->reference}.", "/admin/matters/{$matter->id}"));

            return $member;
        });
    }

    /** The previous responsible lawyer stays on the team as a member. */
    public function setResponsible(Matter $matter, User $user, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $matter);
        $this->assertCanJoin($user, 'responsible');

        DB::transaction(function () use ($matter, $user, $actor) {
            $previous = $matter->responsible()->lockForUpdate()->first();
            if ($previous?->user_id === $user->id) {
                return;
            }
            $previous?->update(['role' => 'member']);

            $existing = $matter->activeTeam()->where('user_id', $user->id)->first();
            $existing ? $existing->update(['role' => 'responsible']) : $this->join($matter, $user, 'responsible', $actor);

            $this->event($matter, 'responsible_changed', "{$user->name} is now the responsible lawyer.", true, $actor, ['from' => $previous?->user_id, 'to' => $user->id]);
            Audit::record('matter.responsible_changed', "{$matter->reference}: responsible lawyer changed to {$user->email}", $matter,
                ['before' => ['user_id' => $previous?->user_id], 'after' => ['user_id' => $user->id]], actor: $actor);
        });
        $matter->unsetRelation('responsible');
    }

    /** Removal ends access immediately and unassigns the person's open tasks on this matter. */
    public function removeTeamMember(MatterTeamMember $member, string $reason, User $actor): void
    {
        $matter = $member->matter;
        Gate::forUser($actor)->authorize('manageTeam', $matter);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record why this person is leaving the matter team.');
        }
        if ($member->removed_at) {
            throw new RuleViolation('This person has already left the team.');
        }
        if ($member->role === 'responsible') {
            throw new RuleViolation('Choose a new responsible lawyer before removing the current one.');
        }

        DB::transaction(function () use ($member, $matter, $reason, $actor) {
            $member->update(['removed_at' => now(), 'removed_by' => $actor->id, 'removal_reason' => $reason]);
            $unassigned = $matter->tasks()->open()->where('assignee_id', $member->user_id)->update(['assignee_id' => null]);
            $name = $member->user?->name ?? 'A team member';
            $this->event($matter, 'team_removed', "{$name} left the team ({$unassigned} open task(s) unassigned): {$reason}", false, $actor, ['user_id' => $member->user_id]);
            Audit::record('matter.team_removed', "{$member->user?->email} removed from {$matter->reference}: {$reason}", $matter,
                context: ['unassigned_tasks' => $unassigned], actor: $actor);
        });
    }

    public function update(Matter $matter, array $data, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $matter);
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        if (isset($data['priority']) && ! isset(Matter::PRIORITIES[$data['priority']])) {
            throw new RuleViolation('Choose a valid priority.');
        }
        if (isset($data['confidentiality']) && ! isset(Matter::CONFIDENTIALITY[$data['confidentiality']])) {
            throw new RuleViolation('Choose a valid confidentiality level.');
        }
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            throw new RuleViolation('The matter needs a title.');
        }

        $matter->fill($data);
        $dirty = $matter->getDirty();
        if ($dirty === []) {
            return;
        }
        $before = array_intersect_key($matter->getOriginal(), $dirty);

        DB::transaction(function () use ($matter, $dirty, $before, $actor) {
            $matter->save();
            // The client sees that the next step changed only when that step is marked client-visible.
            $nextChanged = array_intersect(array_keys($dirty), ['next_action', 'next_action_due_at', 'next_action_client_visible']) !== [];
            if ($nextChanged && $matter->next_action && $matter->next_action_client_visible) {
                $this->event($matter, 'next_action', 'Next step: '.$matter->next_action, true, $actor);
            }
            $this->event($matter, 'updated', 'Matter details updated: '.implode(', ', array_keys($dirty)), false, $actor);
            // Summaries and assessments can be long and sensitive; the audit records which fields changed, not their text.
            Audit::record('matter.updated', "{$matter->reference} updated (".implode(', ', array_keys($dirty)).')', $matter,
                ['fields' => array_keys($dirty), 'before' => array_diff_key($before, array_flip(['internal_assessment', 'client_summary']))], actor: $actor);
        });
    }

    public function changeStage(Matter $matter, string $stage, ?string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $matter);
        $options = $matter->service?->stageOptions() ?? collect(Service::DEFAULT_STAGES)->pluck('label', 'key')->all();
        if (! isset($options[$stage])) {
            throw new RuleViolation('Choose a stage from this service\'s list.');
        }
        if ($matter->stage === $stage) {
            return;
        }
        $from = $matter->stageLabel();

        DB::transaction(function () use ($matter, $stage, $options, $note, $from, $actor) {
            $matter->forceFill(['stage' => $stage])->save();
            $summary = 'Stage changed to '.$options[$stage].($note ? ': '.trim($note) : '.');
            $this->event($matter, 'stage_changed', $summary, true, $actor, ['from' => $from, 'to' => $stage]);
            Audit::record('matter.stage_changed', "{$matter->reference}: {$from} → {$options[$stage]}", $matter, actor: $actor);
        });

        $this->contacts->notify($matter->client, "Update on {$matter->reference}", 'There is an update on your matter.', "/portal/matters/{$matter->id}");
    }

    public function hold(Matter $matter, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $matter);
        if ($matter->status !== MatterStatus::Open) {
            throw new RuleViolation('Only an open matter can be put on hold.');
        }
        $this->requireReason($reason, 'Record why the matter is on hold.');
        $matter->forceFill(['status' => MatterStatus::OnHold])->save();
        $this->event($matter, 'on_hold', 'Matter placed on hold: '.trim($reason), false, $actor);
        Audit::record('matter.on_hold', "{$matter->reference} placed on hold: ".trim($reason), $matter, actor: $actor);
    }

    public function resume(Matter $matter, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $matter);
        if ($matter->status !== MatterStatus::OnHold) {
            throw new RuleViolation('The matter is not on hold.');
        }
        $matter->forceFill(['status' => MatterStatus::Open])->save();
        $this->event($matter, 'resumed', 'Matter resumed.', false, $actor);
        Audit::record('matter.resumed', "{$matter->reference} resumed", $matter, actor: $actor);
    }

    /**
     * @param  array<string, bool>  $checklist  every CLOSURE_CHECKLIST item must be confirmed
     */
    public function close(Matter $matter, array $checklist, string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('close', $matter);
        $missing = array_keys(array_filter(Matter::CLOSURE_CHECKLIST, fn ($label, $key) => empty($checklist[$key]), ARRAY_FILTER_USE_BOTH));
        if ($missing !== []) {
            throw new RuleViolation('Confirm every closure item: '.implode('; ', array_map(fn ($k) => Matter::CLOSURE_CHECKLIST[$k], $missing)).'.');
        }
        $this->requireReason($note, 'Add a closing note.');
        if ($matter->tasks()->open()->exists()) {
            throw new RuleViolation('Complete or cancel the open tasks before closing the matter.');
        }
        if ($matter->documentRequests()->open()->exists()) {
            throw new RuleViolation('Fulfil or cancel the open document requests before closing the matter.');
        }

        DB::transaction(function () use ($matter, $checklist, $note, $actor) {
            $matter->forceFill([
                'status' => MatterStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'closing_note' => trim($note),
                'closure_checklist' => array_map(fn () => true, array_intersect_key($checklist, Matter::CLOSURE_CHECKLIST)) + ['confirmed_at' => now()->toIso8601String(), 'confirmed_by' => $actor->id],
            ])->save();
            $this->event($matter, 'closed', 'Matter closed.', true, $actor);
            Audit::record('matter.closed', "{$matter->reference} closed", $matter, actor: $actor);
        });

        $this->contacts->notify($matter->client, "{$matter->reference} has been closed", 'One of your matters has been closed.', "/portal/matters/{$matter->id}");
    }

    public function reopen(Matter $matter, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('reopen', $matter);
        $this->requireReason($reason, 'Record why the matter is being reopened.');

        DB::transaction(function () use ($matter, $reason, $actor) {
            $matter->forceFill(['status' => MatterStatus::Open, 'closed_at' => null, 'closed_by' => null])->save();
            $this->event($matter, 'reopened', 'Matter reopened: '.trim($reason), false, $actor);
            $this->event($matter, 'reopened_client', 'Matter reopened.', true, $actor);
            Audit::record('matter.reopened', "{$matter->reference} reopened: ".trim($reason), $matter, actor: $actor);
        });
    }

    public function setLegalHold(Matter $matter, bool $hold, string $reason, User $actor): void
    {
        if (! $actor->isFullAdministrator()) {
            throw new RuleViolation('Only a Technical Administrator or Firm Principal can change a legal hold.');
        }
        $this->requireReason($reason, 'Record the reason for the legal hold change.');
        $matter->forceFill(['legal_hold' => $hold])->save();
        $this->event($matter, $hold ? 'legal_hold_on' : 'legal_hold_off', ($hold ? 'Legal hold applied: ' : 'Legal hold released: ').trim($reason), false, $actor);
        Audit::record($hold ? 'matter.legal_hold_on' : 'matter.legal_hold_off', "{$matter->reference}: ".trim($reason), $matter, actor: $actor);
    }

    public function addParty(Matter $matter, string $name, string $role, ?string $notes, User $actor): Party
    {
        Gate::forUser($actor)->authorize('update', $matter);
        if (trim($name) === '' || ! isset(Party::ROLES[$role])) {
            throw new RuleViolation('Enter the party\'s name and role.');
        }
        $party = Party::create(['matter_id' => $matter->id, 'name' => trim($name), 'role' => $role, 'notes' => $notes ? trim($notes) : null, 'added_by' => $actor->id]);
        $this->event($matter, 'party_added', "Party added: {$party->name} (".Party::ROLES[$role].').', false, $actor);
        Audit::record('matter.party_added', "{$matter->reference}: party {$party->name} added", $matter, actor: $actor);

        return $party;
    }

    /** Deadlines are entered manually by staff; nothing is calculated from statute or court rules. */
    public function addDeadline(Matter $matter, array $data, User $actor): MatterDeadline
    {
        Gate::forUser($actor)->authorize('update', $matter);
        if (! isset(MatterDeadline::KINDS[$data['kind'] ?? '']) || trim((string) ($data['title'] ?? '')) === '' || empty($data['due_at'])) {
            throw new RuleViolation('Enter the kind, title and due date.');
        }
        $deadline = $matter->deadlines()->create([
            'kind' => $data['kind'],
            'title' => trim($data['title']),
            'due_at' => Carbon::parse($data['due_at']),
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'client_visible' => (bool) ($data['client_visible'] ?? false),
            'created_by' => $actor->id,
        ]);
        $this->event($matter, 'deadline_added', MatterDeadline::KINDS[$deadline->kind].': '.$deadline->title.' due '.$deadline->due_at->timezone(config('app.firm_timezone'))->format('j M Y'),
            $deadline->client_visible, $actor);
        Audit::record('matter.deadline_added', "{$matter->reference}: {$deadline->kind} '{$deadline->title}' added", $matter, actor: $actor);

        return $deadline;
    }

    public function completeDeadline(MatterDeadline $deadline, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $deadline->matter);
        if ($deadline->completed_at) {
            return;
        }
        $deadline->update(['completed_at' => now(), 'completed_by' => $actor->id]);
        $this->event($deadline->matter, 'deadline_completed', 'Completed: '.$deadline->title, $deadline->client_visible, $actor);
        Audit::record('matter.deadline_completed', "{$deadline->matter->reference}: '{$deadline->title}' completed", $deadline->matter, actor: $actor);
    }

    public function event(Matter $matter, string $type, string $summary, bool $clientVisible, ?User $actor, ?array $data = null): MatterEvent
    {
        return MatterEvent::create([
            'matter_id' => $matter->id,
            'type' => $type,
            'summary' => mb_substr($summary, 0, 500),
            'client_visible' => $clientVisible,
            'actor_id' => $actor?->id,
            'data' => $data,
            'created_at' => now(),
        ]);
    }

    private function join(Matter $matter, User $user, string $role, User $actor): MatterTeamMember
    {
        return $matter->team()->create(['user_id' => $user->id, 'role' => $role, 'added_by' => $actor->id, 'added_at' => now()]);
    }

    private function assertCanJoin(User $user, string $role): void
    {
        if (! $user->isActive()) {
            throw new RuleViolation('That account is not active.');
        }
        if ($role === 'responsible' && ! $user->hasRole(Role::Lawyer)) {
            throw new RuleViolation('The responsible person must be an active lawyer.');
        }
        if (! $user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            throw new RuleViolation('Only lawyers and case officers can join a matter team.');
        }
    }

    private function requireReason(string $reason, string $message): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation($message);
        }
    }
}
