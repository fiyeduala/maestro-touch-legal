<?php

namespace App\Listeners;

use App\Domain\Operations\Settings;
use App\Models\Delivery;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Applies the sender identity from Settings → Email to every outgoing message, and keeps a
 * metadata-only delivery log (recipient, type, subject, outcome). Message bodies are never stored.
 * SMTP credentials stay in .env and are never read or logged here.
 */
class MailActivity
{
    public function sending(MessageSending $event): void
    {
        $message = $event->message;
        if (! $message instanceof Email) {
            return;
        }
        try {
            $address = Settings::get('mail.from_address');
            $name = (string) Settings::get('mail.from_name');
            $replyTo = Settings::get('mail.reply_to');
        } catch (Throwable) {
            return; // settings table unavailable (e.g. during install): keep the .env defaults
        }

        $from = $address ?: ($message->getFrom()[0] ?? null)?->getAddress() ?: config('mail.from.address');
        if ($from) {
            $message->from(new Address($from, $name !== '' ? $name : (string) config('mail.from.name')));
        }
        if ($replyTo && $message->getReplyTo() === []) {
            $message->replyTo($replyTo);
        }
    }

    public function sent(MessageSent $event): void
    {
        $message = $event->sent->getOriginalMessage();
        if (! $message instanceof Email) {
            return;
        }
        $type = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null;
        foreach ([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()] as $to) {
            $this->record($type, $to->getAddress(), $message->getSubject(), 'sent');
        }
    }

    /** A queued notification that gave up after its retries. The recipient is not known at this point. */
    public function failed(JobFailed $event): void
    {
        $name = $event->job->resolveName();
        if ($name !== SendQueuedNotifications::class && ! str_starts_with((string) ($event->job->payload()['displayName'] ?? ''), 'App\\Notifications')) {
            return;
        }
        $this->record($event->job->payload()['displayName'] ?? $name, '(queued notification)', null, 'failed', $event->exception->getMessage());
    }

    private function record(?string $type, string $recipient, ?string $subject, string $status, ?string $error = null): void
    {
        try {
            Delivery::create([
                'channel' => 'mail',
                'type' => $type ? Str::limit(class_basename($type), 115) : null,
                'recipient' => Str::limit($recipient, 250),
                'subject' => $subject ? Str::limit($subject, 250) : null,
                'status' => $status,
                'error' => $error ? Str::limit($error, 480) : null,
            ]);
        } catch (Throwable $e) {
            report($e); // never let logging break sending
        }
    }
}
