<?php

namespace App\Notifications\Recruitment;

use App\Models\StaffApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InformationRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StaffApplication $application, public string $message, #[\SensitiveParameter] public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("More information needed – {$this->application->reference}")
            ->greeting("Dear {$this->application->full_name},")
            ->line('Our team has reviewed your application and needs a little more information:')
            ->line($this->message)
            ->action('Respond', route('careers.application.show', $this->token))
            ->line('Earlier links to your application no longer work; please use this one.');
    }
}
