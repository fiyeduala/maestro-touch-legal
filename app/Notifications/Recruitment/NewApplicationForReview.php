<?php

namespace App\Notifications\Recruitment;

use App\Models\StaffApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Internal alert. Contains no CV contents or applicant contact details beyond the name. */
class NewApplicationForReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StaffApplication $application, public bool $responded = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->responded
            ? "Applicant responded – {$this->application->reference}"
            : "New staff application – {$this->application->reference}";

        return (new MailMessage)
            ->subject($subject)
            ->line($this->responded
                ? "{$this->application->full_name} has responded to your request for more information."
                : "{$this->application->full_name} has applied to join the legal team ({$this->application->professional_category}).")
            ->action('Review in admin', url('/admin/staff-applications/'.$this->application->id));
    }
}
