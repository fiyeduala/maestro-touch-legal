<?php

namespace App\Notifications\Recruitment;

use App\Models\StaffApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when an application is declined. Approval is communicated by the invitation email. */
class ApplicationDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StaffApplication $application) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your application – {$this->application->reference}")
            ->greeting("Dear {$this->application->full_name},")
            ->line('Thank you for your interest in joining Maestro Touch Legal and for the time you put into your application.')
            ->line('After careful consideration, we are not able to take your application further at this time.')
            ->line('We wish you every success.');
    }
}
