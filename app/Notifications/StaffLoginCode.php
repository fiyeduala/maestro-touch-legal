<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The staff sign-in code. Sent straight away rather than queued: the queue only drains on the
 * five-minute cron, so a queued code could arrive after it had expired.
 */
class StaffLoginCode extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $code, public int $codeExpiryMinutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Maestro Touch Legal sign-in code')
            ->line("Your sign-in code is: {$this->code}")
            ->line("It expires in {$this->codeExpiryMinutes} minutes. If you did not try to sign in, change your password and tell the firm principal.");
    }
}
