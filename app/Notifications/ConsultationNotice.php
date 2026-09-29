<?php

namespace App\Notifications;

use App\Models\Consultation;
use App\Support\Ics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Consultation emails to the client or enquirer: requested, confirmed, rescheduled, cancelled, reminder.
 * Times are shown in West Africa Time. Confirmed bookings carry a calendar invitation. The client's
 * agenda and any internal outcome notes are never included.
 */
class ConsultationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $consultationId, public string $kind) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $consultation = Consultation::with('type')->findOrFail($this->consultationId);
        $when = $consultation->starts_at->timezone(config('app.firm_timezone'))->format('l j F Y, g:i a').' West Africa Time (WAT)';
        $name = $consultation->type->name;

        $mail = (new MailMessage)->greeting('Hello '.$consultation->contact_name.',');
        $mail = match ($this->kind) {
            'requested' => $mail->subject("Consultation request received: {$consultation->reference}")
                ->line("We have received your request for a {$name} on {$when}.")
                ->line('The firm will confirm the booking by email. Your request does not yet mean the firm has agreed to act for you.'),
            'confirmed' => $mail->subject("Consultation confirmed: {$when}")
                ->line("Your {$name} is confirmed for {$when}.")
                ->line($consultation->meeting_url ? 'Join using the link below at the time of the consultation.' : 'The firm will tell you how the consultation will take place.'),
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

        if ($consultation->status === 'confirmed' && $consultation->meeting_url && in_array($this->kind, ['confirmed', 'rescheduled', 'reminder'], true)) {
            $mail->action('Join the consultation', $consultation->meeting_url);
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
                description: 'Consultation '.$consultation->reference.($consultation->meeting_url ? "\nJoin: ".$consultation->meeting_url : ''),
                location: $consultation->meeting_url,
                cancelled: $this->kind === 'cancelled',
            ), 'consultation.ics', ['mime' => 'text/calendar; charset=utf-8']);
        }

        return $mail->salutation('Maestro Touch Legal');
    }
}
