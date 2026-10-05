<?php

namespace App\Domain\Consultations;

use App\Domain\Clients\ClientContacts;
use App\Domain\Identity\Role;
use App\Domain\Intake\Enquiries;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\Operations\Audit;
use App\Domain\Operations\Settings;
use App\Domain\Operations\StaffNotifier;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\Enquiry;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\ConsultationNotice;
use App\Notifications\StaffAlert;
use App\Support\Money;
use App\Support\References;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Consultation booking (spec §11). Working hours, buffers, capacity, notice periods and blocked dates
 * come from Settings (firm timezone); times are stored in UTC and shown as WAT. Every booking or move
 * takes the "consultations" row in booking_locks FOR UPDATE and re-checks availability inside the same
 * transaction, so two requests can never take the last place in a slot.
 *
 * Meeting links are pasted by staff and must be HTTPS; nothing here creates Zoom/Meet meetings.
 * A consultation is not an engagement: it never starts representation.
 */
class Consultations
{
    public function __construct(private ClientContacts $contacts, private Enquiries $enquiries) {}

    /**
     * Bookable start times for a type, grouped by local date.
     *
     * @return array<string, list<Carbon>> 'Y-m-d' (firm time) => UTC start times
     */
    public function slots(ConsultationType $type, ?Carbon $now = null, ?int $ignoreId = null): array
    {
        $now ??= now();
        $tz = config('app.firm_timezone');
        $buffer = (int) Settings::get('consultations.buffer_minutes');
        $earliest = $now->copy()->addHours((int) Settings::get('consultations.min_notice_hours'));
        $today = $now->copy()->timezone($tz)->startOfDay();
        $lastDay = $today->copy()->addDays((int) Settings::get('consultations.max_days_ahead'));
        $booked = $this->bookedBetween($today->copy()->utc(), $lastDay->copy()->addDay()->utc(), $ignoreId);

        $slots = [];
        for ($day = $today->copy(); $day->lessThanOrEqualTo($lastDay); $day->addDay()) {
            if ($this->isBlocked($day)) {
                continue;
            }
            foreach ((array) Settings::get('consultations.hours') as $hours) {
                if ((int) ($hours['day'] ?? 0) !== $day->dayOfWeekIso) {
                    continue;
                }
                $start = $day->copy()->setTimeFromTimeString($hours['start']);
                $close = $day->copy()->setTimeFromTimeString($hours['end']);
                for (; $start->copy()->addMinutes($type->duration_minutes)->lessThanOrEqualTo($close); $start->addMinutes($type->duration_minutes + $buffer)) {
                    $utcStart = $start->copy()->utc();
                    if ($utcStart->lessThan($earliest)) {
                        continue;
                    }
                    if ($this->hasRoom($booked, $utcStart, $utcStart->copy()->addMinutes($type->duration_minutes), $buffer)) {
                        $slots[$day->toDateString()][] = $utcStart;
                    }
                }
            }
        }

        return $slots;
    }

    /** A client contact asks for a slot from the portal. The firm confirms it separately. */
    public function request(User $user, Client $client, ConsultationType $type, Carbon $startsAt, ?string $agenda, ?Matter $matter = null): Consultation
    {
        if ($user->isFullAdministrator() || ! $user->isActive() || ! $user->hasRole(Role::Client) || ! $user->clients()->whereKey($client->id)->exists()) {
            throw new AuthorizationException('You cannot book for this client.');
        }
        if (! $type->is_active || ! $type->is_public) {
            throw new RuleViolation('That type of consultation is not available.');
        }
        if ($matter && ($matter->client_id !== $client->id || Gate::forUser($user)->denies('actAsClient', $matter))) {
            throw new AuthorizationException('You cannot book for that matter.');
        }
        $agenda = filled($agenda) ? mb_substr(trim($agenda), 0, 2000) : null;

        $consultation = DB::transaction(function () use ($user, $client, $type, $startsAt, $agenda, $matter) {
            $this->lock();
            $this->assertOffered($type, $startsAt);

            $consultation = Consultation::create([
                'reference' => References::next('consultations', 'CON'),
                'consultation_type_id' => $type->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'requested_by' => $user->id,
                'contact_name' => $user->name,
                'contact_email' => $user->email,
                'starts_at' => $startsAt->copy()->utc(),
                'ends_at' => $startsAt->copy()->utc()->addMinutes($type->duration_minutes),
                'status' => 'requested',
                'client_agenda' => $agenda,
            ]);
            Audit::record('consultation.requested', "{$consultation->reference} requested for {$this->local($consultation)}", $consultation, actor: $user);

            return $consultation;
        });

        $user->notify(new ConsultationNotice($consultation->id, 'requested'));
        $this->alertStaff($consultation, "New consultation request {$consultation->reference}", 'A client has asked for a consultation. Please confirm or suggest another time.');

        return $consultation;
    }

    /**
     * Staff book a consultation for an enquiry or for a client contact. Staff may book outside published
     * hours, but never into a full time or a host's existing booking.
     *
     * @param  array{type_id: int, starts_at: Carbon, host_id?: ?int, enquiry_id?: ?int, matter_id?: ?int, contact_user_id?: ?int, meeting_url?: ?string, video?: bool, confirm?: bool}  $data
     */
    public function schedule(array $data, User $actor): Consultation
    {
        Gate::forUser($actor)->authorize('create', Consultation::class);
        $type = ConsultationType::where('is_active', true)->findOrFail($data['type_id']);
        $startsAt = $data['starts_at']->copy()->utc();
        if ($startsAt->isPast()) {
            throw new RuleViolation('Choose a time in the future.');
        }

        $enquiry = ! empty($data['enquiry_id']) ? Enquiry::findOrFail($data['enquiry_id']) : null;
        $matter = ! empty($data['matter_id']) ? Matter::findOrFail($data['matter_id']) : null;
        if ($enquiry) {
            Gate::forUser($actor)->authorize('update', $enquiry);
            if (! $enquiry->status->isOpen()) {
                throw new RuleViolation('This enquiry is closed.');
            }
            [$name, $email, $clientId] = [$enquiry->contact_name, $enquiry->contact_email, $enquiry->client_id];
        } elseif ($matter) {
            Gate::forUser($actor)->authorize('update', $matter);
            $contact = $this->contacts->portalUsers($matter->client)->firstWhere('id', $data['contact_user_id'] ?? null);
            if (! $contact) {
                throw new RuleViolation('Choose which client contact the consultation is with.');
            }
            [$name, $email, $clientId] = [$contact->name, $contact->email, $matter->client_id];
        } else {
            throw new RuleViolation('Link the consultation to an enquiry or a matter.');
        }

        $host = $this->host($data['host_id'] ?? null);
        // A video call on this site (D50) replaces a pasted link.
        $video = (bool) ($data['video'] ?? false);
        $url = $video ? null : $this->meetingUrl($data['meeting_url'] ?? null);
        $confirm = (bool) ($data['confirm'] ?? false);
        if ($confirm && ! $host) {
            throw new RuleViolation('Choose who will host the consultation before confirming it.');
        }

        $consultation = DB::transaction(function () use ($type, $startsAt, $enquiry, $matter, $name, $email, $clientId, $host, $url, $video, $confirm, $actor) {
            $this->lock();
            $endsAt = $startsAt->copy()->addMinutes($type->duration_minutes);
            $this->assertRoom($startsAt, $endsAt, $host);

            $consultation = Consultation::create([
                'reference' => References::next('consultations', 'CON'),
                'consultation_type_id' => $type->id,
                'client_id' => $clientId,
                'enquiry_id' => $enquiry?->id,
                'matter_id' => $matter?->id,
                'contact_name' => $name,
                'contact_email' => $email,
                'host_id' => $host?->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $confirm ? 'confirmed' : 'requested',
                'confirmed_at' => $confirm ? now() : null,
                'meeting_url' => $url,
                'video' => $video,
            ]);
            if ($enquiry) {
                $this->enquiries->advance($enquiry, EnquiryStatus::ConsultationScheduled, $actor, "Consultation {$consultation->reference} booked for {$this->local($consultation)}");
            }
            Audit::record('consultation.scheduled', "{$consultation->reference} booked by staff for {$this->local($consultation)}", $consultation, actor: $actor);

            return $consultation;
        });

        if ($confirm) {
            $this->notifyContact($consultation, 'confirmed');
        }

        return $consultation;
    }

    /** Confirms a request (or updates the host/link of a confirmed booking) and sends the invitation. $video: a call on this site (D50). */
    public function confirm(Consultation $consultation, ?int $hostId, ?string $meetingUrl, User $actor, bool $video = false): void
    {
        Gate::forUser($actor)->authorize('update', $consultation);
        if (! $consultation->isActive()) {
            throw new RuleViolation('This consultation is no longer active.');
        }
        if ($consultation->starts_at->isPast()) {
            throw new RuleViolation('This consultation has already started.');
        }
        $hostId ??= $consultation->host_id;
        if ($hostId !== $consultation->host_id) {
            Gate::forUser($actor)->authorize('assignHost', $consultation);
        }
        $host = $this->host($hostId);
        if (! $host) {
            throw new RuleViolation('Choose who will host the consultation.');
        }
        $url = $video ? null : $this->meetingUrl($meetingUrl);

        DB::transaction(function () use ($consultation, $host, $url, $video, $actor) {
            $this->lock();
            $this->assertRoom($consultation->starts_at, $consultation->ends_at, $host, $consultation->id, checkCapacity: false);
            $before = $consultation->only(['status', 'host_id', 'meeting_url', 'video']);
            $consultation->forceFill([
                'status' => 'confirmed',
                'host_id' => $host->id,
                'meeting_url' => $url,
                'video' => $video,
                'confirmed_at' => $consultation->confirmed_at ?? now(),
                'sequence' => $consultation->sequence + 1,
            ])->save();
            Audit::record('consultation.confirmed', "{$consultation->reference} confirmed for {$this->local($consultation)} with {$host->name}", $consultation,
                ['before' => $before, 'after' => $consultation->only(['status', 'host_id', 'meeting_url', 'video'])], actor: $actor);
        });

        $this->notifyContact($consultation, 'confirmed');
    }

    /**
     * Staff may move a booking at any time before it starts. A client may move their own booking to another
     * published slot until the change cut-off; the firm then confirms the new time.
     */
    public function reschedule(Consultation $consultation, Carbon $startsAt, User $actor): void
    {
        $asClient = $this->actor($consultation, $actor);
        if (! $consultation->isActive()) {
            throw new RuleViolation('This consultation is no longer active.');
        }
        if ($asClient) {
            $this->assertBeforeCutoff($consultation);
        } elseif ($startsAt->isPast()) {
            throw new RuleViolation('Choose a time in the future.');
        }

        DB::transaction(function () use ($consultation, $startsAt, $actor, $asClient) {
            $this->lock();
            $startsAt = $startsAt->copy()->utc();
            $endsAt = $startsAt->copy()->addMinutes($consultation->type->duration_minutes);
            $asClient
                ? $this->assertOffered($consultation->type, $startsAt, $consultation->id)
                : $this->assertRoom($startsAt, $endsAt, $consultation->host, $consultation->id);

            $from = $this->local($consultation);
            $consultation->forceFill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $asClient ? 'requested' : $consultation->status,
                'reminders_sent' => null,
                'sequence' => $consultation->sequence + 1,
            ])->save();
            Audit::record('consultation.rescheduled', "{$consultation->reference} moved from {$from} to {$this->local($consultation)}".($asClient ? ' by the client' : ''),
                $consultation, actor: $actor);
        });

        $this->notifyContact($consultation, 'rescheduled');
        if ($asClient) {
            $this->alertStaff($consultation, "Consultation {$consultation->reference} moved by the client", 'The client has asked for a new time. Please confirm it.');
        }
    }

    public function cancel(Consultation $consultation, ?string $reason, User $actor): void
    {
        $asClient = $this->actor($consultation, $actor);
        if (! $consultation->isActive()) {
            throw new RuleViolation('This consultation is no longer active.');
        }
        $reason = trim((string) $reason);
        if ($asClient) {
            $this->assertBeforeCutoff($consultation);
        } elseif (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record why the consultation is cancelled.');
        }

        DB::transaction(function () use ($consultation, $reason, $actor, $asClient) {
            $consultation->forceFill([
                'status' => 'cancelled',
                'cancel_reason' => $reason !== '' ? mb_substr($reason, 0, 2000) : null,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'sequence' => $consultation->sequence + 1,
            ])->save();
            Audit::record('consultation.cancelled', "{$consultation->reference} cancelled".($asClient ? ' by the client' : ''), $consultation, actor: $actor);
        });

        $this->notifyContact($consultation, 'cancelled');
        if ($asClient) {
            $this->alertStaff($consultation, "Consultation {$consultation->reference} cancelled by the client", 'The client has cancelled a consultation.');
        }
    }

    /** Attendance and internal outcome notes, recorded after the start time. Never shown to the client. */
    public function recordOutcome(Consultation $consultation, string $status, ?string $outcome, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $consultation);
        if (! in_array($status, ['completed', 'no_show'], true)) {
            throw new RuleViolation('Choose whether the consultation took place.');
        }
        if (! $consultation->isActive() && ! in_array($consultation->status, ['completed', 'no_show'], true)) {
            throw new RuleViolation('A cancelled consultation has no outcome.');
        }
        if ($consultation->starts_at->isFuture()) {
            throw new RuleViolation('The outcome can be recorded once the consultation has started.');
        }
        $before = $consultation->only(['status']);
        $consultation->forceFill(['status' => $status, 'outcome' => filled($outcome) ? trim($outcome) : $consultation->outcome])->save();
        Audit::record('consultation.outcome_recorded', "{$consultation->reference}: ".Consultation::STATUSES[$status], $consultation,
            ['before' => $before, 'after' => ['status' => $status]], actor: $actor);
    }

    /**
     * Sends at most one reminder per consultation per call: the latest threshold that has passed.
     * Missed earlier thresholds (for example after downtime) are not sent late.
     */
    public function sendReminders(?Carbon $now = null, int $limit = 100): int
    {
        $now ??= now();
        $thresholds = collect((array) Settings::get('consultations.reminder_hours'))->map(fn ($h) => (int) $h)->filter(fn ($h) => $h > 0)->unique()->sort()->values();
        if ($thresholds->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $due = Consultation::where('status', 'confirmed')->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addHours($thresholds->max()))
            ->orderBy('starts_at')->limit($limit)->get();
        foreach ($due as $consultation) {
            $passed = $thresholds->filter(fn ($h) => $now->greaterThanOrEqualTo($consultation->starts_at->copy()->subHours($h)));
            $already = collect($consultation->reminders_sent ?? [])->map(fn ($h) => (int) $h);
            $latest = $passed->min();
            if ($latest === null || $already->contains($latest)) {
                continue;
            }
            // Claim first so an overlapping run cannot send the same reminder.
            $claimed = Consultation::whereKey($consultation->id)->where('updated_at', $consultation->updated_at)
                ->update(['reminders_sent' => json_encode($already->merge($passed)->unique()->sort()->values()->all()), 'updated_at' => now()]);
            if ($claimed && $this->notifyContact($consultation, 'reminder')) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> staff who can host consultations */
    /** Creates or updates a consultation type. Types are deactivated, never deleted: bookings keep pointing at them. */
    public function saveType(?ConsultationType $type, array $data, User $actor): ConsultationType
    {
        $type
            ? Gate::forUser($actor)->authorize('update', $type)
            : Gate::forUser($actor)->authorize('create', ConsultationType::class);

        $name = trim((string) ($data['name'] ?? ''));
        $duration = (int) ($data['duration_minutes'] ?? 0);
        if ($name === '') {
            throw new RuleViolation('Enter a name.');
        }
        if ($duration < 10 || $duration > 480) {
            throw new RuleViolation('The length must be between 10 and 480 minutes.');
        }
        $free = (bool) ($data['is_free'] ?? true);
        [$fee, $currency] = [null, null];
        if (! $free) {
            try {
                $currency = Money::assertCurrency((string) ($data['currency'] ?? ''));
                $fee = Money::parse((string) ($data['fee'] ?? ''));
            } catch (\InvalidArgumentException) {
                throw new RuleViolation('Enter the fee as an amount with up to two decimals, and choose NGN or USD.');
            }
            if ($fee < 1) {
                throw new RuleViolation('A paid consultation needs a fee above zero.');
            }
        }

        return DB::transaction(function () use ($type, $data, $name, $duration, $free, $fee, $currency, $actor) {
            $type ??= new ConsultationType;
            $fields = ['name', 'duration_minutes', 'is_free', 'fee_minor', 'currency', 'is_public', 'is_active'];
            $before = $type->exists ? $type->only($fields) : null;
            $type->fill([
                'name' => $name,
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'duration_minutes' => $duration,
                'is_free' => $free,
                'fee_minor' => $fee,
                'currency' => $currency,
                'is_public' => (bool) ($data['is_public'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'sort' => (int) ($data['sort'] ?? 0),
            ])->save();

            Audit::record($before ? 'consultation_type.updated' : 'consultation_type.created',
                "Consultation type \"{$type->name}\" ".($before ? 'updated' : 'created'), $type,
                $before ? ['before' => $before, 'after' => $type->only($fields)] : null, actor: $actor);

            return $type;
        });
    }

    public function hosts(): Collection
    {
        return User::active()->withActiveRole(Role::Lawyer, Role::CaseOfficer, ...Role::fullAdministratorRoles())->orderBy('name')->get();
    }

    public function local(Consultation $consultation): string
    {
        return $consultation->starts_at->timezone(config('app.firm_timezone'))->format('D j M Y, g:i a').' WAT';
    }

    /** Emails the contact, checking current portal access for client contacts. Returns false when nobody was emailed. */
    private function notifyContact(Consultation $consultation, string $kind): bool
    {
        $user = User::where('email', mb_strtolower($consultation->contact_email))->first();
        if ($user && ! $user->isStaff()) {
            if (! $user->isActive() || ($consultation->client_id && ! $user->clients()->whereKey($consultation->client_id)->exists())) {
                return false;
            }
            $user->notify(new ConsultationNotice($consultation->id, $kind));

            return true;
        }
        Notification::route('mail', $consultation->contact_email)->notify(new ConsultationNotice($consultation->id, $kind));

        return true;
    }

    private function alertStaff(Consultation $consultation, string $subject, string $line): void
    {
        $alert = new StaffAlert($subject, $line, "/admin/consultations/{$consultation->id}");
        $consultation->host && $consultation->host->isActive()
            ? StaffNotifier::users([$consultation->host], $alert)
            : ($consultation->matter
                ? $this->contacts->alertStaff(null, $consultation->matter, $subject, $line, "/admin/consultations/{$consultation->id}")
                : StaffNotifier::administrators($alert, 'Consultation request'));
    }

    /** True when acting as the client; staff must be allowed to manage it. */
    private function actor(Consultation $consultation, User $actor): bool
    {
        if ($actor->isStaff()) {
            Gate::forUser($actor)->authorize('update', $consultation);

            return false;
        }
        Gate::forUser($actor)->authorize('actAsClient', $consultation);

        return true;
    }

    private function assertBeforeCutoff(Consultation $consultation): void
    {
        $hours = (int) Settings::get('consultations.client_change_cutoff_hours');
        if (now()->addHours($hours)->greaterThan($consultation->starts_at)) {
            throw new RuleViolation("Changes can be made online up to {$hours} hours before the consultation. Please message the firm instead.");
        }
    }

    private function assertOffered(ConsultationType $type, Carbon $startsAt, ?int $ignoreId = null): void
    {
        $offered = collect($this->slots($type, null, $ignoreId))->flatten()->contains(fn (Carbon $slot) => $slot->equalTo($startsAt));
        if (! $offered) {
            throw new RuleViolation('That time is no longer available. Please choose another.');
        }
    }

    private function assertRoom(Carbon $startsAt, Carbon $endsAt, ?User $host, ?int $ignoreId = null, bool $checkCapacity = true): void
    {
        $buffer = (int) Settings::get('consultations.buffer_minutes');
        $booked = $this->bookedBetween($startsAt->copy()->subDay(), $endsAt->copy()->addDay(), $ignoreId);
        if ($checkCapacity && ! $this->hasRoom($booked, $startsAt, $endsAt, $buffer)) {
            throw new RuleViolation('The firm is fully booked at that time.');
        }
        if ($host && ! $this->hasRoom($booked->where('host_id', $host->id), $startsAt, $endsAt, $buffer, 1)) {
            throw new RuleViolation("{$host->name} already has a consultation at that time.");
        }
    }

    private function hasRoom(Collection $booked, Carbon $start, Carbon $end, int $buffer, ?int $capacity = null): bool
    {
        $capacity ??= max(1, (int) Settings::get('consultations.capacity'));
        $from = $start->copy()->subMinutes($buffer);
        $to = $end->copy()->addMinutes($buffer);

        return $booked->filter(fn (Consultation $c) => $c->starts_at->lessThan($to) && $c->ends_at->greaterThan($from))->count() < $capacity;
    }

    private function bookedBetween(Carbon $from, Carbon $to, ?int $ignoreId): Collection
    {
        return Consultation::active()->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get(['id', 'starts_at', 'ends_at', 'host_id']);
    }

    private function isBlocked(Carbon $localDay): bool
    {
        $date = $localDay->toDateString();
        foreach ((array) Settings::get('consultations.blocked') as $block) {
            if (($block['from'] ?? null) && $date >= $block['from'] && $date <= ($block['to'] ?? $block['from'])) {
                return true;
            }
        }

        return false;
    }

    private function host(?int $hostId): ?User
    {
        if (! $hostId) {
            return null;
        }
        $host = $this->hosts()->firstWhere('id', $hostId);
        if (! $host) {
            throw new RuleViolation('The host must be an active lawyer, case officer or administrator.');
        }

        return $host;
    }

    /** Staff paste the link from their own meeting tool. HTTPS only, no embedded credentials. */
    private function meetingUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (mb_strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuleViolation('Enter a full https:// meeting link.');
        }

        return $url;
    }

    private function lock(): void
    {
        DB::table('booking_locks')->where('name', 'consultations')->lockForUpdate()->first();
    }
}
