<?php

namespace App\Notifications;

use App\Notifications\Concerns\InAppAndPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Internal alert with a link into the admin panel, by email, in the panel's bell and as a browser push (D49).
 * Callers keep wording short and free of evidence contents. $mail = false for routine activity that only
 * needs to appear in the bell (for example a colleague adding a document).
 */
class StaffAlert extends Notification implements ShouldQueue
{
    use InAppAndPush, Queueable;

    public function __construct(public string $subject, public string $line, public string $path = '/admin', public bool $mail = true) {}

    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, $this->mail);
    }

    protected function inApp(object $notifiable): array
    {
        return ['title' => $this->subject, 'body' => $this->line, 'url' => $this->path];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->line)
            ->action('Open in admin', url($this->path));
    }
}
