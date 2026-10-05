<?php

namespace App\Domain\Meetings;

use App\Domain\Clients\ClientContacts;
use App\Domain\Operations\Audit;
use App\Models\Consultation;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Video\DailyVideo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Who may enter a video call and when (D50). Callers have already signed the person in; this decides
 * whether they are invited, whether the call is open, and then asks Daily for the room and a token.
 * Results are plain arrays for the join page: ['ok' => true, 'url', 'token', ...] or ['ok' => false, 'reason'].
 */
class VideoRooms
{
    public function __construct(private DailyVideo $daily, private ClientContacts $contacts) {}

    /** @return array<string, mixed> */
    public function joinMeeting(Meeting $meeting, User $user): array
    {
        if (Gate::forUser($user)->denies('join', $meeting)) {
            return $this->refuse('not_invited');
        }
        // A client contact whose portal access was removed after the invitation can no longer join.
        if (! $user->isStaff() && (! $meeting->client || ! $this->contacts->portalUsers($meeting->client)->contains('id', $user->id))) {
            return $this->refuse('not_invited');
        }
        if (! $meeting->isScheduled()) {
            return $this->refuse('cancelled');
        }

        return $this->enter($meeting, 'mtg', $meeting->title, $meeting->starts_at, $meeting->ends_at,
            (string) $user->id, $user->name, $meeting->organiser_id === $user->id, $user);
    }

    /** Staff on the consultation, or a client contact of its client. */
    public function joinConsultation(Consultation $consultation, User $user): array
    {
        $staff = $user->isStaff() && Gate::forUser($user)->allows('update', $consultation);
        if (! $staff && Gate::forUser($user)->denies('actAsClient', $consultation)) {
            return $this->refuse('not_invited');
        }

        return $this->consultationRoom($consultation, (string) $user->id, $user->name, $staff && $consultation->host_id === $user->id, $user);
    }

    /** The emailed link for a contact without a portal account; the signature has already been checked. */
    public function joinConsultationAsGuest(Consultation $consultation): array
    {
        return $this->consultationRoom($consultation, 'guest-'.$consultation->id, $consultation->contact_name, false, null);
    }

    /** Signed join link for a consultation contact without an account. It stops working when the call closes. */
    public static function guestLink(Consultation $consultation): string
    {
        return URL::temporarySignedRoute('meet.consultation.guest', self::closes($consultation->ends_at), ['consultation' => $consultation->id]);
    }

    public static function opens(Carbon $startsAt): Carbon
    {
        return $startsAt->copy()->subMinutes(max(0, (int) config('video.join_early_minutes', 15)));
    }

    public static function closes(Carbon $endsAt): Carbon
    {
        return $endsAt->copy()->addMinutes(max(0, (int) config('video.join_late_minutes', 60)));
    }

    public static function isOpen(Carbon $startsAt, Carbon $endsAt): bool
    {
        return now()->between(self::opens($startsAt), self::closes($endsAt));
    }

    private function consultationRoom(Consultation $consultation, string $id, string $name, bool $owner, ?User $user): array
    {
        if (! $consultation->video) {
            return $this->refuse('not_video');
        }
        if ($consultation->status !== 'confirmed') {
            return $this->refuse($consultation->status === 'requested' ? 'not_confirmed' : 'cancelled');
        }

        return $this->enter($consultation, 'con', $consultation->type->name.' '.$consultation->reference,
            $consultation->starts_at, $consultation->ends_at, $id, $name, $owner, $user);
    }

    /** @param Meeting|Consultation $subject */
    private function enter(Model $subject, string $prefix, string $title, Carbon $startsAt, Carbon $endsAt, string $id, string $name, bool $owner, ?User $user): array
    {
        $base = ['title' => $title, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'opens_at' => self::opens($startsAt)];
        if (now()->lessThan(self::opens($startsAt))) {
            return $this->refuse('early') + $base;
        }
        if (now()->greaterThan(self::closes($endsAt))) {
            return $this->refuse('over') + $base;
        }

        $until = self::closes($endsAt);
        $room = $this->daily->ensureRoom($subject->video_room, $prefix, $subject, $until);
        if (! $room['room']) {
            return $this->refuse($room['error']) + $base;
        }
        if ($subject->video_room !== $room['room']) {
            $subject->forceFill(['video_room' => $room['room']])->save();
        }
        $token = $this->daily->token($room['room'], $id, $name, $owner, $until);
        if (! $token) {
            return $this->refuse(DailyVideo::UNREACHABLE) + $base;
        }

        $reference = $subject->getAttribute('reference');
        Audit::record('video.joined', "{$name} joined the video call for {$reference}", $subject, actor: $user,
            context: ['room' => $room['room'], 'owner' => $owner]);

        return ['ok' => true, 'url' => $this->daily->roomUrl($room['room']), 'token' => $token, 'domain' => $this->daily->domain(), 'name' => $name] + $base;
    }

    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
