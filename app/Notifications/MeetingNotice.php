<?php

namespace App\Notifications;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\Concerns\InAppAndPush;
use App\Support\Ics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Video meeting invitations, changes, cancellations and reminders (D50), by email with a calendar file,
 * in the notifications bell and as a push. Staff see the meeting title; client contacts get generic
 * wording (the title may name the matter) and see the details after signing in. The join link goes to
 * this site, which checks the person is invited before handing them a Daily token.
 */
class MeetingNotice extends Notification implements ShouldQueue
{
    use InAppAndPush, Queueable;

    public int $tries = 3;

    public function __construct(public int $meetingId, public string $kind) {}

    public function via(object $notifiable): array
    {
        return $this->channels($notifiable);
    }

    protected function inApp(object $notifiable): array
    {
        $meeting = Meeting::findOrFail($this->meetingId);
        $staff = $notifiable instanceof User && $notifiable->isStaff();
        $what = $staff ? "\"{$meeting->title}\"" : 'Your video meeting with Maestro Touch Legal';
        $when = $this->when($meeting);

        return [
            'title' => match ($this->kind) {
                'invited' => 'Video meeting invitation',
                'rescheduled' => 'Video meeting moved',
                'cancelled' => 'Video meeting cancelled',
                'reminder' => 'Video meeting starting soon',
                default => 'Video meeting update',
            },
            'body' => match ($this->kind) {
                'invited' => ($staff ? "{$what} on {$when}." : "You are invited to a video meeting with Maestro Touch Legal on {$when}."),
                'rescheduled' => "{$what} is now on {$when}.",
                'cancelled' => "{$what} on {$when} has been cancelled.",
                'reminder' => "{$what} starts at ".$meeting->starts_at->timezone(config('app.firm_timezone'))->format('g:i a').' WAT. Join from the link.',
                default => "{$what}: {$when}.",
            },
            'url' => $this->link($meeting, $staff, false),
            'icon' => 'heroicon-o-video-camera',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $meeting = Meeting::with('organiser')->findOrFail($this->meetingId);
        $message = $this->inApp($notifiable);
        $staff = $notifiable instanceof User && $notifiable->isStaff();

        $mail = (new MailMessage)->subject($message['title'].': '.$this->when($meeting))
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($message['body']);
        if ($staff && $meeting->agenda && $this->kind === 'invited') {
            $mail->line('Agenda: '.$meeting->agenda);
        }
        if ($this->kind === 'cancelled') {
            $mail->line($meeting->cancel_reason ? 'Reason: '.$meeting->cancel_reason : 'There is nothing you need to do.');
        } else {
            $mail->line('The call opens '.(int) config('video.join_early_minutes', 15).' minutes before the start. You will be asked to sign in, then to allow your camera and microphone.')
                ->action($staff ? 'Open the meeting' : 'Join the video meeting', url($message['url']))
                ->line('The call is private to the people invited and is not recorded.');
        }

        if ($this->kind !== 'reminder') {
            $mail->attachData(Ics::event(
                uid: "meeting-{$meeting->id}@".parse_url(config('app.url'), PHP_URL_HOST),
                sequence: $meeting->sequence,
                start: $meeting->starts_at,
                end: $meeting->ends_at,
                summary: $staff ? $meeting->title : 'Video meeting with Maestro Touch Legal',
                description: "Meeting {$meeting->reference}\nJoin: ".$this->link($meeting, $staff),
                location: $this->link($meeting, $staff),
                cancelled: $this->kind === 'cancelled',
            ), 'meeting.ics', ['mime' => 'text/calendar; charset=utf-8']);
        }

        return $mail->salutation('Maestro Touch Legal');
    }

    /** Staff open the meeting in the staff portal (sign-in with 2-step there), clients go straight to the call page. */
    private function link(Meeting $meeting, bool $staff, bool $absolute = true): string
    {
        if ($staff) {
            return $absolute ? url("/admin/meetings/{$meeting->id}") : "/admin/meetings/{$meeting->id}";
        }

        return $this->kind === 'cancelled' ? ($absolute ? url('/portal/appointments') : '/portal/appointments') : route('meet.meeting', $meeting, $absolute);
    }

    private function when(Meeting $meeting): string
    {
        return $meeting->starts_at->timezone(config('app.firm_timezone'))->format('l j F Y, g:i a').' WAT';
    }
}
