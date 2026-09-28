<?php

namespace App\Notifications\Recruitment;

use App\Models\StaffApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StaffApplication $application, #[\SensitiveParameter] public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Application received – {$this->application->reference}")
            ->greeting("Dear {$this->application->full_name},")
            ->line('Thank you for applying to join the Maestro Touch Legal team. We have received your application and supporting documents.')
            ->line("Your reference is {$this->application->reference}.")
            ->line('You can check its status, respond to any request for more information, or withdraw it using the private link below. Please keep this email; the link is personal to you.')
            ->action('View my application', route('careers.application.show', $this->token));
    }
}
