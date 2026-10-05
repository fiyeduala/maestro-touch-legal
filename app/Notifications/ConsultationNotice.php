<?php

namespace App\Notifications;

use App\Domain\Meetings\VideoRooms;
use App\Models\Consultation;
use App\Models\User;
use App\Notifications\Concerns\InAppAndPush;
use App\Support\Ics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Consultation emails to the client or enquirer: requested, confirmed, rescheduled, cancelled, reminder.
 * Times are shown in West Africa Time. Confirmed bookings carry a calendar invitation. The client's
 * agenda and any internal outcome notes are never included. A video consultation (D50) links to this site's
 * join page: the sign-in route for a portal user, a signed link that closes with the call for anyone else.
 * Portal users also get it in their notifications and as a push (D49).
 */
class ConsultationNotice extends Notification implements ShouldQueue
{
    use InAppAndPush, Queueable;

    public int $tries = 3;

    public function __construct(public int $consultationId, public string $kind) {}

    public function via(object $notifiable): array
    {
        return $this->channels($notifiable);
    }

    protected function inApp(object $notifiable): array
    {
        $consultation = Consultation::with('type')->findOrFail($this->consultationId);
        $when = $consultation->starts_at->timezone(config('app.firm_timezone'))->format('D j M, g:i a').' WAT';

        return [
            'title' => match ($this->kind) {
                'requested' => 'Consultation request received',
                'confirmed' => 'Consultation confirmed',
                'rescheduled' => 'Consultation moved',
                'cancelled' => 'Consultation cancelled',
                'reminder' => 'Consultation reminder',
                default => 'Consultation update',
            },
            'body' => "{$consultation->type->name}, {$when}.",
            'url' => '/portal/appointments',
            'icon' => 'heroicon-o-calendar-days',
        ];
    }

    /** Where the contact joins: the video page for a video consultation, otherwise the pasted link. */
    private function joinUrl(Consultation $consultation, object $notifiable): ?string
    {
        if ($consultation->video) {
            return $notifiable instanceof User ? route('meet.consultation', $consultation) : VideoRooms::guestLink($consultation);
        }

        return $consultation->meeting_url;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $consultation = Consultation::with('type')->findOrFail($this->consultationId);
        $when = $consultation->starts_at->timezone(config('app.firm_timezone'))->format('l j F Y, g:i a').' West Africa Time (WAT)';
        $name = $consultation->type->name;
        $join = $this->joinUrl($consultation, $notifiable);

        $mail = (new MailMessage)->greeting('Hello '.$consultation->contact_name.',');
        $mail = match ($this->kind) {
            'requested' => $mail->subject("Consultation request received: {$consultation->reference}")
                ->line("We have received your request for a {$name} on {$when}.")
                ->line('The firm will confirm the booking by email. Your request does not yet mean the firm has agreed to act for you.'),
            'confirmed' => $mail->subject("Consultation confirmed: {$when}")
                ->line("Your {$name} is confirmed for {$when}.")
                ->line($consultation->video
                    ? 'This is a video call on our website. Use the button below at the time of the consultation; it opens '.(int) config('video.join_early_minutes', 15).' minutes before the start. Your browser will ask to use your camera and microphone. The call is not recorded.'
                    : ($join ? 'Join using the link below at the time of the consultation.' : 'The firm will tell you how the consultation will take place.')),
            'rescheduled' => $mail->subject("Consultation moved: {$when}")
                ->line("Your {$name} ({$consultation->reference}) is now on {$when}.")
                ->line($consultation->status === 'requested' ? 'The firm will confirm the new time by email.' : 'An updated calendar invitation is attached.'),
            'cancelled' => $mail->subject("Consultation cancelled: {$consultation->reference}")
                ->line("Your {$name} planned for {$when} has been cancelled.")
                ->line('If you would still like to speak with us, you can request a new time.'),
            'reminder' => $mail->subject("Reminder: consultation on {$when}")
                ->line("This is a reminder of your {$name} on {$when}."),
            default => $mail->subject('Consultation update')->line('There is an update to your consultation.'),
        };

        if ($consultation->status === 'confirmed' && $join && in_array($this->kind, ['confirmed', 'rescheduled', 'reminder'], true)) {
            $mail->action($consultation->video ? 'Join the video call' : 'Join the consultation', $join);
        } elseif ($consultation->client_id) {
            $mail->action('View in your portal', url('/portal/appointments'));
        }

        if (in_array($this->kind, ['confirmed', 'cancelled'], true) || ($this->kind === 'rescheduled' && $consultation->status === 'confirmed')) {
            $mail->attachData(Ics::event(
                uid: "consultation-{$consultation->id}@".parse_url(config('app.url'), PHP_URL_HOST),
                sequence: $consultation->sequence,
                start: $consultation->starts_at,
                end: $consultation->ends_at,
                summary: "{$name} with Maestro Touch Legal",
                description: 'Consultation '.$consultation->reference.($join ? "\nJoin: ".$join : ''),
                location: $join,
                cancelled: $this->kind === 'cancelled',
            ), 'consultation.ics', ['mime' => 'text/calendar; charset=utf-8']);
        }

        return $mail->salutation('Maestro Touch Legal');
    }
}
