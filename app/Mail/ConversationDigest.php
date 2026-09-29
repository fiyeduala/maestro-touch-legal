<?php

namespace App\Mail;

use App\Models\Digest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * The end-of-day conversation email. Only client-conversation messages are passed in (never internal
 * notes); attached files are never included, only a note that a file was shared with a sign-in link.
 * Plain HTML (not Markdown), so message text cannot turn into links or formatting.
 */
class ConversationDigest extends Mailable
{
    use Queueable;

    public function __construct(public Digest $digest, public Collection $sections) {}

    public function envelope(): Envelope
    {
        $date = $this->digest->window_end->timezone(config('app.firm_timezone'))->format('j M Y');

        return new Envelope(subject: $this->digest->kind === 'firm'
            ? "Client conversations: daily recap for {$date}"
            : "Your conversation with Maestro Touch Legal: {$date}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.digest',
            text: 'emails.digest-text',
            with: [
                'digest' => $this->digest,
                'sections' => $this->sections,
                'tz' => config('app.firm_timezone'),
                'firm' => $this->digest->kind === 'firm',
            ],
        );
    }
}
