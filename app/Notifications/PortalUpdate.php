<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Client-facing email: minimal text and an authenticated portal link (spec §8). Never carries matter
 * details, document contents, attachments or internal notes; callers pass generic wording only.
 */
class PortalUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public string $line, public string $path = '/portal') {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->line)
            ->action('Sign in to your portal', url($this->path))
            ->line('For your privacy, details are only available after you sign in.');
    }
}
