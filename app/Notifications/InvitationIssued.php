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
        $roles = collect($this->invitation->roles)->map(fn ($r) => Role::from($r)->label())->join(', ', ' and ');

        return (new MailMessage)
            ->subject('Your invitation to Maestro Touch Legal')
            ->greeting("Hello {$this->invitation->name},")
            ->line("You have been invited to join Maestro Touch Legal as {$roles}.")
            ->action('Accept invitation', route('invitation.show', $this->token))
            ->line('This link expires on '.$this->invitation->expires_at->timezone(config('app.firm_timezone'))->format('j F Y, g:i a').' (Lagos time).')
            ->line('You will choose your own password. Staff accounts must also set up two-step verification on first sign-in.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
