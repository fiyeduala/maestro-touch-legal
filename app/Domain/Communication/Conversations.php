<?php

namespace App\Domain\Communication;

use App\Domain\Documents\Documents;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Jobs\SendMessageNotices;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Matter conversations (spec §8). Two audiences per matter:
 *  - "client": the private client–firm chat, seen by the client's active contacts and the matter team;
 *  - "internal": staff-only notes, never shown to clients or included in client emails, digests or exports.
 * Messages are saved immediately and never edited or deleted; a correction is a new, audited amendment.
 * Full administrators always post as the firm, never as a client.
 */
class Conversations
{
    public const MAX_LENGTH = 5000;

    /** Minutes to wait before emailing "you have a new message", so a reply seen in the portal sends nothing. */
    public const NOTICE_DELAY_MINUTES = 10;

    public function __construct(private Documents $documents) {}

    public function send(Matter $matter, User $sender, string $audience, string $body, ?UploadedFile $file = null): Message
    {
        $asClient = $this->assertCanPost($matter, $sender, $audience);
        $body = $this->cleanBody($body, $file !== null);

        $document = null;
        if ($file) {
            $document = $asClient
                ? $this->documents->clientUpload($matter, $file, $file->getClientOriginalName(), null, 'Attached to a message', $sender, alertStaff: false)
                : $this->attachAsStaff($matter, $file, $audience, $sender);
        }

        $message = $this->create($matter, $sender, $audience, [
            'kind' => 'message',
            'sender_is_client' => $asClient,
            'body' => $body !== '' ? $body : 'Sent a file.',
            'document_id' => $document?->id,
        ]);

        return $message;
    }

    /** A staff record of a phone, WhatsApp or in-person conversation. The audience is always chosen explicitly. */
    public function callNote(Matter $matter, User $staff, string $audience, string $channel, string $body): Message
    {
        if ($this->assertCanPost($matter, $staff, $audience)) {
            throw new AuthorizationException('Only staff can record a conversation note.');
        }
        if (! array_key_exists($channel, Message::CHANNELS)) {
            throw new RuleViolation('Choose how the conversation took place.');
        }

        return $this->create($matter, $staff, $audience, [
            'kind' => 'call_note',
            'channel' => $channel,
            'sender_is_client' => false,
            'body' => $this->cleanBody($body, false),
        ]);
    }

    /** A correction to one of the author's own messages. The original stays visible and unchanged. */
    public function amend(Message $original, User $author, string $body): Message
    {
        if ($original->sender_id !== $author->id) {
            throw new AuthorizationException('Only the author of a message can correct it.');
        }
        $matter = $original->matter;
        $asClient = $this->assertCanPost($matter, $author, $original->audience);
        if ($asClient !== $original->sender_is_client) {
            throw new AuthorizationException('This message cannot be corrected from this account.');
        }

        $message = $this->create($matter, $author, $original->audience, [
            'kind' => 'amendment',
            'sender_is_client' => $asClient,
            'body' => $this->cleanBody($body, false),
            'amends_message_id' => $original->id,
            'channel' => $original->channel,
        ]);
        Audit::record('message.amended', "{$matter->reference}: correction #{$message->id} added to message #{$original->id} ({$original->audience})",
            $message, context: ['audience' => $original->audience], actor: $author);

        return $message;
    }

    /**
     * Messages for display, oldest first. $afterId fetches only newer ones (polling);
     * $beforeId pages back through history.
     *
     * @return Collection<int, Message>
     */
    public function thread(Matter $matter, string $audience, ?int $afterId = null, ?int $beforeId = null, int $limit = 30): Collection
    {
        return Message::where('matter_id', $matter->id)->where('audience', $audience)
            ->when($afterId, fn (Builder $q) => $q->where('id', '>', $afterId)->orderBy('id')->limit(100))
            ->when(! $afterId, fn (Builder $q) => $q->when($beforeId, fn (Builder $q) => $q->where('id', '<', $beforeId))->orderByDesc('id')->limit($limit))
            ->with(['sender:id,name', 'document'])
            ->get()
            ->sortBy('id')
            ->values();
    }

    /** Records that $reader has seen these messages (their own are skipped). Returns how many were newly marked. */
    public function markRead(Collection $messages, User $reader): int
    {
        $rows = $messages->filter(fn (Message $m) => $m->sender_id !== $reader->id)
            ->map(fn (Message $m) => ['message_id' => $m->id, 'user_id' => $reader->id, 'read_at' => now()])
            ->values()->all();

        return $rows ? DB::table('message_reads')->insertOrIgnore($rows) : 0;
    }

    /**
     * When the other side first read each of these messages: by any client contact for firm messages,
     * by any staff member for client messages.
     *
     * @return array<int, \Illuminate\Support\Carbon> message id => first read time
     */
    public function readByOtherSide(Collection $messages): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $reads = MessageRead::whereIn('message_id', $messages->modelKeys())->with('user')->orderBy('read_at')->get()->groupBy('message_id');
        $result = [];
        foreach ($messages as $message) {
            $first = ($reads[$message->id] ?? collect())->first(function (MessageRead $read) use ($message) {
                $readerIsClient = $read->user && ! $read->user->isStaff();

                return $message->sender_is_client ? ! $readerIsClient : $readerIsClient;
            });
            if ($first) {
                $result[$message->id] = $first->read_at;
            }
        }

        return $result;
    }

    /** Messages in the given audiences of this matter that $user has not read (their own excluded). */
    public function unreadQuery(User $user, array $audiences): Builder
    {
        return Message::whereIn('audience', $audiences)
            ->where(fn (Builder $q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
            ->whereDoesntHave('reads', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    /**
     * Unread counts per matter for a client contact (client chat only, on matters they can still see).
     *
     * @return array<int, int> matter id => unread count
     */
    public function clientUnread(User $user): array
    {
        return $this->unreadQuery($user, ['client'])
            ->whereIn('matter_id', Matter::whereIn('client_id', $user->clients()->select('clients.id'))->select('id'))
            ->groupBy('matter_id')->selectRaw('matter_id, count(*) as aggregate')->pluck('aggregate', 'matter_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Unread counts per matter for staff: both audiences, on matters the user may open.
     *
     * @return array<int, array{client: int, internal: int}>
     */
    public function staffUnread(User $user): array
    {
        $rows = $this->unreadQuery($user, ['client', 'internal'])
            ->whereIn('matter_id', Matter::visibleTo($user)->select('id'))
            ->groupBy('matter_id', 'audience')->selectRaw('matter_id, audience, count(*) as aggregate')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[$row->matter_id] ??= ['client' => 0, 'internal' => 0];
            $result[$row->matter_id][$row->audience] = (int) $row->aggregate;
        }

        return $result;
    }

    /**
     * Who may post, and as whom. Returns true when posting as the client.
     * Staff on the team (or full administrators) post as the firm; client contacts post only to the client chat.
     */
    private function assertCanPost(Matter $matter, User $user, string $audience): bool
    {
        if (! in_array($audience, ['client', 'internal'], true)) {
            throw new RuleViolation('Unknown conversation.');
        }
        if ($user->isStaff()) {
            if (Gate::forUser($user)->denies('view', $matter)) {
                throw new AuthorizationException('You are not on this matter\'s team.');
            }
            if ($matter->isClosed()) {
                throw new RuleViolation('This matter is closed. Reopen it before adding messages.');
            }

            return false;
        }
        if ($audience !== 'client' || Gate::forUser($user)->denies('actAsClient', $matter)) {
            throw new AuthorizationException('You cannot post in this conversation.');
        }
        if ($matter->isClosed()) {
            throw new RuleViolation('This matter is closed. Please start a new request if you need more help.');
        }

        return true;
    }

    private function cleanBody(string $body, bool $hasFile): string
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' && ! $hasFile) {
            throw new RuleViolation('Write a message first.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new RuleViolation('Messages can be up to '.number_format(self::MAX_LENGTH).' characters.');
        }

        return $body;
    }

    /** Files staff attach in the client chat are shared with the client; internal-note files stay internal. */
    private function attachAsStaff(Matter $matter, UploadedFile $file, string $audience, User $staff): Document
    {
        $document = $this->documents->upload($matter, $file, [
            'title' => mb_substr($file->getClientOriginalName(), 0, 190),
            'category' => 'correspondence',
            'note' => $audience === 'client' ? 'Sent in the client conversation' : 'Attached to an internal note',
        ], $staff);
        if ($audience === 'client') {
            $this->documents->release($document, 'Sent in the conversation.', $staff, notifyClient: false);
        }

        return $document->refresh();
    }

    private function create(Matter $matter, User $sender, string $audience, array $attributes): Message
    {
        $message = Message::create($attributes + ['matter_id' => $matter->id, 'audience' => $audience, 'sender_id' => $sender->id]);
        // The author has obviously read their own message; recorded so unread counts stay simple for colleagues.
        MessageRead::insertOrIgnore(['message_id' => $message->id, 'user_id' => $sender->id, 'read_at' => now()]);

        if ($audience === 'client') {
            SendMessageNotices::dispatch($message->id)->delay(now()->addMinutes(self::NOTICE_DELAY_MINUTES));
        }

        return $message;
    }
}
