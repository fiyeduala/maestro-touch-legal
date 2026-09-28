<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Internal alert with a link into the admin panel. Callers keep wording short and free of evidence contents. */
class StaffAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public string $line, public string $path = '/admin') {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->line)
            ->action('Open in admin', url($this->path));
    }
}
