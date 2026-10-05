<?php

namespace App\Domain\Meetings;

use App\Domain\Clients\ClientContacts;
use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingNotice;
use App\Support\References;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Scheduled video meetings (D50): staff with staff, and staff with client contacts. Full administrators
 * may invite any client's portal contacts; lawyers and case officers only those of matters they are on.
 * Everyone invited gets the invitation by email, in their notifications and as a push, with a calendar
 * file. The call itself runs on Daily.co (see VideoRooms); scheduling never depends on Daily being up.
 */
class Meetings
{
    public function __construct(private ClientContacts $contacts) {}

    /**
     * @param  array{title: string, agenda?: ?string, starts_at: Carbon, duration_minutes: int, matter_id?: ?int, client_id?: ?int, staff_ids?: list<int>, client_user_ids?: list<int>}  $data
     */
    public function schedule(array $data, User $actor): Meeting
    {
        Gate::forUser($actor)->authorize('create', Meeting::class);
        $title = trim((string) ($data['title'] ?? ''));
        if (mb_strlen($title) < 3) {
            throw new RuleViolation('Give the meeting a title.');
        }
        [$startsAt, $endsAt] = $this->times($data['starts_at'], (int) ($data['duration_minutes'] ?? 0));
        [$matter, $client] = $this->link($data, $actor);
        $people = $this->people($data, $client)->reject(fn (User $u) => $u->id === $actor->id);
        if ($people->isEmpty()) {
            throw new RuleViolation('Invite at least one other person.');
        }

        $meeting = DB::transaction(function () use ($title, $data, $startsAt, $endsAt, $matter, $client, $people, $actor) {
            $meeting = Meeting::create([
                'reference' => References::next('meetings', 'MTG'),
                'title' => mb_substr($title, 0, 200),
                'agenda' => filled($data['agenda'] ?? null) ? mb_substr(trim($data['agenda']), 0, 5000) : null,
                'matter_id' => $matter?->id,
                'client_id' => $client?->id,
                'organiser_id' => $actor->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'scheduled',
            ]);
            $meeting->participants()->sync([$actor->id, ...$people->modelKeys()]);
            Audit::record('meeting.scheduled', "{$meeting->reference} \"{$meeting->title}\" scheduled for {$this->local($meeting)} with {$people->pluck('name')->implode(', ')}",
                $meeting, actor: $actor);

            return $meeting;
        });

        $this->notify($people, $meeting, 'invited');

        return $meeting;
    }

    public function reschedule(Meeting $meeting, Carbon $startsAt, int $durationMinutes, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $meeting);
        $this->assertOpen($meeting);
        [$startsAt, $endsAt] = $this->times($startsAt, $durationMinutes);

        $from = $this->local($meeting);
        $meeting->forceFill(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'reminded_at' => null, 'sequence' => $meeting->sequence + 1])->save();
        Audit::record('meeting.rescheduled', "{$meeting->reference} moved from {$from} to {$this->local($meeting)}", $meeting, actor: $actor);

        $this->notify($this->others($meeting, $actor), $meeting, 'rescheduled');
    }

    /** @param array{staff_ids?: list<int>, client_user_ids?: list<int>} $data */
    public function invite(Meeting $meeting, array $data, User $actor): int
    {
        Gate::forUser($actor)->authorize('update', $meeting);
        $this->assertOpen($meeting);
        if (! empty($data['client_user_ids']) && ! $meeting->client) {
            throw new RuleViolation('Client contacts can only join a meeting linked to their matter or client.');
        }
        $existing = $meeting->participants()->pluck('users.id');
        $people = $this->people($data, $meeting->client)->reject(fn (User $u) => $existing->contains($u->id));
        if ($people->isEmpty()) {
            throw new RuleViolation('Everyone chosen is already invited.');
        }
        $meeting->participants()->syncWithoutDetaching($people->modelKeys());
        Audit::record('meeting.invited', "{$meeting->reference}: invited {$people->pluck('name')->implode(', ')}", $meeting, actor: $actor);
        $this->notify($people, $meeting, 'invited');

        return $people->count();
    }

    public function cancel(Meeting $meeting, ?string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $meeting);
        $this->assertOpen($meeting);
        $reason = trim((string) $reason);

        $meeting->forceFill([
            'status' => 'cancelled',
            'cancel_reason' => $reason !== '' ? mb_substr($reason, 0, 2000) : null,
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
            'sequence' => $meeting->sequence + 1,
        ])->save();
        Audit::record('meeting.cancelled', "{$meeting->reference} cancelled", $meeting, actor: $actor);

        $this->notify($this->others($meeting, $actor), $meeting, 'cancelled');
    }

    /** One reminder per meeting, shortly before it starts, to everyone invited (the organiser included). */
    public function sendReminders(?Carbon $now = null, int $limit = 100): int
    {
        $now ??= now();
        $minutes = max(1, (int) config('video.reminder_minutes', 15));
        $sent = 0;
        $due = Meeting::where('status', 'scheduled')->whereNull('reminded_at')
            ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addMinutes($minutes))
            ->orderBy('starts_at')->limit($limit)->get();
        foreach ($due as $meeting) {
            // Claim first so an overlapping run cannot send the same reminder.
            if (Meeting::whereKey($meeting->id)->whereNull('reminded_at')->update(['reminded_at' => $now])) {
                $this->notify($meeting->participants()->get(), $meeting, 'reminder');
                $sent++;
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> active staff who can be invited */
    public function staff(): Collection
    {
        return User::active()->withActiveRole(...Role::staffRoles())->orderBy('name')->get();
    }

    /** @return Collection<int, User> client contacts $actor may invite for $client */
    public function clientContacts(?Client $client): Collection
    {
        return $client ? $this->contacts->portalUsers($client) : collect();
    }

    public function local(Meeting $meeting): string
    {
        return $meeting->starts_at->timezone(config('app.firm_timezone'))->format('D j M Y, g:i a').' WAT';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function times(Carbon $startsAt, int $durationMinutes): array
    {
        $startsAt = $startsAt->copy()->utc();
        if ($startsAt->isPast()) {
            throw new RuleViolation('Choose a time in the future.');
        }
        if ($durationMinutes < 10 || $durationMinutes > 480) {
            throw new RuleViolation('The length must be between 10 and 480 minutes.');
        }

        return [$startsAt, $startsAt->copy()->addMinutes($durationMinutes)];
    }

    /** @return array{0: ?Matter, 1: ?Client} */
    private function link(array $data, User $actor): array
    {
        if (! empty($data['matter_id'])) {
            $matter = Matter::with('client')->findOrFail($data['matter_id']);
            Gate::forUser($actor)->authorize('update', $matter);

            return [$matter, $matter->client];
        }
        if (! empty($data['client_id'])) {
            if (! $actor->isFullAdministrator()) {
                throw new RuleViolation('Link the meeting to one of your matters to invite the client.');
            }

            return [null, Client::findOrFail($data['client_id'])];
        }
        if (! empty($data['client_user_ids'])) {
            throw new RuleViolation('Link the meeting to a matter or client to invite client contacts.');
        }

        return [null, null];
    }

    /** @return Collection<int, User> */
    private function people(array $data, ?Client $client): Collection
    {
        $staffIds = collect($data['staff_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $clientIds = collect($data['client_user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();

        $staff = $this->staff()->whereIn('id', $staffIds)->values();
        if ($staff->count() !== $staffIds->count()) {
            throw new RuleViolation('Only active staff can be invited as colleagues.');
        }
        $contacts = $this->clientContacts($client)->whereIn('id', $clientIds)->values();
        if ($contacts->count() !== $clientIds->count()) {
            throw new RuleViolation('Only active portal contacts of this client can be invited.');
        }

        return $staff->merge($contacts)->unique('id')->values();
    }

    /** @return Collection<int, User> */
    private function others(Meeting $meeting, User $actor): Collection
    {
        return $meeting->participants()->get()->reject(fn (User $u) => $u->id === $actor->id)->values();
    }

    private function assertOpen(Meeting $meeting): void
    {
        if (! $meeting->isScheduled()) {
            throw new RuleViolation('This meeting has been cancelled.');
        }
        if ($meeting->ends_at->isPast()) {
            throw new RuleViolation('This meeting has already ended.');
        }
    }

    private function notify(Collection $people, Meeting $meeting, string $kind): void
    {
        $recipients = $people->filter(fn (User $u) => $u->isActive())->unique('id');
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new MeetingNotice($meeting->id, $kind));
        }
    }
}
