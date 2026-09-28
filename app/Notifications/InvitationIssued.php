<?php

namespace App\Notifications;

use App\Domain\Identity\Role;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invitation $invitation, #[\SensitiveParameter] public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expires = 'This link expires on '.$this->invitation->expires_at->timezone(config('app.firm_timezone'))->format('j F Y, g:i a').' (Lagos time).';

        // Client portal invitations: no role names or staff security wording.
        if ($this->invitation->client_id) {
            return (new MailMessage)
                ->subject('Your Maestro Touch Legal client portal invitation')
                ->greeting("Hello {$this->invitation->name},")
                ->line('Maestro Touch Legal has invited you to use its secure client portal, where you can follow your matters, share documents and respond to the firm.')
                ->action('Accept invitation', route('invitation.show', $this->token))
                ->line($expires)
                ->line('You will choose your own password. The firm will never ask you for it.')
                ->line('If you were not expecting this invitation, you can ignore this email.');
        }

        $roles = collect($this->invitation->roles)->map(fn ($r) => Role::from($r)->label())->join(', ', ' and ');

        return (new MailMessage)
            ->subject('Your invitation to Maestro Touch Legal')
            ->greeting("Hello {$this->invitation->name},")
            ->line("You have been invited to join Maestro Touch Legal as {$roles}.")
            ->action('Accept invitation', route('invitation.show', $this->token))
            ->line($expires)
            ->line('You will choose your own password. Staff accounts must also set up two-step verification on first sign-in.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
