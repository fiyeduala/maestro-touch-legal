<?php

namespace App\Notifications\Intake;

use App\Models\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the address given on a public enquiry. Does not repeat what was submitted (the address is
 * unverified), and restates that an enquiry alone does not create a lawyer–client relationship.
 * Wording is new copy pending owner approval (docs/content-gaps.md §7).
 */
class EnquiryAcknowledgement extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Enquiry $enquiry) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("We have received your enquiry – {$this->enquiry->reference}")
            ->greeting('Hello '.$this->enquiry->contact_name.',')
            ->line('Thank you for contacting Maestro Touch Legal. Your enquiry reference is '.$this->enquiry->reference.'.')
            ->line('A member of our team will review it and contact you. Please quote the reference if you get in touch.')
            ->line('Please note: submitting an enquiry does not by itself make Maestro Touch Legal your lawyers. We will confirm in writing if and when we agree to act for you.')
            ->line('If you did not make this enquiry, you can ignore this email.');
    }
}
