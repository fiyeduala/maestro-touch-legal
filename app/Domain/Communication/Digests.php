<?php

namespace App\Domain\Communication;

use App\Domain\Clients\ClientContacts;
use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\Operations\Settings;
use App\Domain\RuleViolation;
use App\Mail\ConversationDigest;
use App\Models\Client;
use App\Models\Digest;
use App\Models\DigestRun;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * End-of-day conversation emails (spec §8).
 *
 * Each run covers client-conversation messages created after the previous planned cutoff and at or
 * before the latest due cutoff (default 18:00 Africa/Lagos). Missed days are covered by the next run
 * (catch-up), and anything after the cutoff waits for the next one. One digest row is stored per
 * recipient and window, keyed so it can only be planned once; sending claims each row atomically.
 *
 * Client emails go to each active, verified contact of one client only (one email per person and
 * client, never CC). Firm emails go only to active full administrators listed in digest.firm_recipients.
 * Access is checked again immediately before each email is built. Internal notes are never selected.
 *
 * SMTP can time out after the server accepted a message, so exactly-once delivery cannot be
 * guaranteed: a row stuck in "sending" becomes "uncertain" and is only re-sent by an audited retry.
 */
class Digests
{
    public const MAX_ATTEMPTS = 3;

    public const STUCK_AFTER_MINUTES = 30;

    public function __construct(private ClientContacts $contacts) {}

    /** The most recent cutoff at or before $now, in UTC. */
    public function dueCutoff(Carbon $now): Carbon
    {
        $tz = config('app.firm_timezone');
        [$hour, $minute] = array_map('intval', explode(':', (string) Settings::get('digest.time')) + [1 => 0]);
        $local = $now->copy()->timezone($tz);
        $cutoff = $local->copy()->setTime($hour, $minute);
        if ($cutoff->greaterThan($local)) {
            $cutoff->subDay();
        }

        return $cutoff->utc();
    }

    /** @return array{planned: int, sent: int, failed: int, skipped: int, uncertain: int} */
    public function run(?Carbon $now = null, int $batch = 40, int $maxSeconds = 150): array
    {
        $now ??= now();
        $result = ['planned' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'uncertain' => $this->markStuck($now)];
        if (! Settings::get('digest.enabled')) {
            return $result;
        }

        $result['planned'] = $this->plan($this->dueCutoff($now));

        return array_merge($result, $this->send($batch, $maxSeconds));
    }

    /** Creates the digest rows for a cutoff (idempotent; resumes a run interrupted while planning). */
    public function plan(Carbon $cutoff): int
    {
        // Finish any earlier run that was interrupted while planning, so windows never overlap.
        $planned = 0;
        foreach (DigestRun::where('status', 'planning')->where('cutoff_at', '<', $cutoff)->orderBy('cutoff_at')->get() as $stale) {
            $planned += $this->plan($stale->cutoff_at);
        }

        $previous = DigestRun::where('status', 'planned')->orderByDesc('cutoff_at')->first();
        if ($previous && $previous->cutoff_at->greaterThanOrEqualTo($cutoff)) {
            return 0;
        }

        $run = DigestRun::where('cutoff_at', $cutoff)->first();
        if (! $run) {
            try {
                $run = DigestRun::create([
                    'cutoff_at' => $cutoff,
                    // First ever run: the preceding 24 hours.
                    'window_start' => $previous?->cutoff_at ?? $cutoff->copy()->subDay(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return 0; // another process is planning this cutoff
            }
        }

        $messages = Message::clientVisible()
            ->where('created_at', '>', $run->window_start)->where('created_at', '<=', $run->cutoff_at)
            ->with('matter:id,client_id')
            ->orderBy('id')->get(['id', 'matter_id']);

        $rows = [];
        foreach ($messages->groupBy(fn (Message $m) => $m->matter->client_id) as $clientId => $clientMessages) {
            $client = Client::find($clientId);
            if (! $client) {
                continue;
            }
            foreach ($this->contacts->portalUsers($client) as $contact) {
                $rows[] = $this->row($run, 'client', $contact, $client, $clientMessages, $contact->clients()->whereKey($client->id)->first()?->pivot?->digest_mode === 'summary' ? 'summary' : 'full');
            }
        }
        if ($messages->isNotEmpty()) {
            foreach ($this->firmRecipients() as $admin) {
                $rows[] = $this->row($run, 'firm', $admin, null, $messages, 'full');
            }
            if ($this->firmRecipients()->isEmpty()) {
                Log::info('Firm conversation digest not sent: no active full administrator is listed in the digest recipients setting.');
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Digest::insertOrIgnore($chunk);
        }
        $run->update(['status' => 'planned', 'digest_count' => $run->digests()->count()]);

        return $planned + count($rows);
    }

    /** @return array{sent: int, failed: int, skipped: int} */
    public function send(int $batch, int $maxSeconds): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $started = microtime(true);
        $candidates = Digest::where(fn ($q) => $q->where('status', 'pending')
            ->orWhere(fn ($q) => $q->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)))
            ->orderBy('id')->limit($batch)->pluck('id');

        foreach ($candidates as $id) {
            if (microtime(true) - $started > $maxSeconds) {
                break;
            }
            // Atomic claim: only one process can move a row into "sending".
            $claimed = Digest::whereKey($id)->whereIn('status', ['pending', 'failed'])->where('attempts', '<', self::MAX_ATTEMPTS)
                ->update(['status' => 'sending', 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $counts[$this->deliver(Digest::findOrFail($id))]++;
        }

        return $counts;
    }

    /** Rows left in "sending" (process killed or SMTP timeout) are not re-sent automatically. */
    public function markStuck(Carbon $now): int
    {
        return Digest::where('status', 'sending')->where('claimed_at', '<', $now->copy()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->update(['status' => 'uncertain', 'last_error' => 'Sending did not finish; the email may or may not have been delivered.', 'updated_at' => now()]);
    }

    /** Audited manual retry of a failed or uncertain digest. The content is rebuilt, and access rechecked, when it is sent. */
    public function retry(Digest $digest, User $actor): void
    {
        if (! $actor->isFullAdministrator()) {
            throw new RuleViolation('Only a full administrator can resend a digest.');
        }
        if (! in_array($digest->status, ['failed', 'uncertain', 'skipped'], true)) {
            throw new RuleViolation('Only failed, uncertain or unsent digests can be sent again.');
        }
        $before = $digest->status;
        $digest->update(['status' => 'pending', 'attempts' => 0, 'claimed_at' => null, 'skipped_reason' => null]);
        Audit::record('digest.retried', "Digest #{$digest->id} to {$digest->email} queued again (was {$before})", $digest,
            ['before' => ['status' => $before], 'after' => ['status' => 'pending']], actor: $actor);
    }

    /**
     * What a digest email contains, rebuilt at send time from the stored message IDs.
     *
     * @return Collection<int, array{matter: \App\Models\Matter, client: ?Client, summary: bool, messages: Collection}>
     */
    public function sections(Digest $digest): Collection
    {
        $messages = Message::clientVisible()->whereIn('id', $digest->message_ids)
            ->with(['sender:id,name', 'matter.client', 'document:id,title'])
            ->orderBy('created_at')->orderBy('id')->get()
            // A matter moved to another client after planning is never shown to the old client's contacts.
            ->when($digest->kind === 'client', fn ($m) => $m->filter(fn (Message $msg) => $msg->matter->client_id === $digest->client_id));

        return $messages->groupBy('matter_id')->map(fn (Collection $items) => [
            'matter' => $items->first()->matter,
            'client' => $items->first()->matter->client,
            'summary' => $digest->mode === 'summary' || $items->first()->matter->digestMode() === 'summary',
            'messages' => $items->values(),
        ])->sortBy(fn ($s) => [$s['client']?->display_name, $s['matter']->reference])->values();
    }

    /** Active full administrators whose address is listed in digest.firm_recipients. */
    public function firmRecipients(): Collection
    {
        $listed = collect((array) Settings::get('digest.firm_recipients'))->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter();
        if ($listed->isEmpty()) {
            return collect();
        }

        return User::active()->withActiveRole(...Role::fullAdministratorRoles())->whereIn('email', $listed)->orderBy('id')->get();
    }

    private function deliver(Digest $digest): string
    {
        $sections = collect();
        $reason = $this->accessProblem($digest);
        if ($reason === null) {
            $sections = $this->sections($digest);
            $reason = $sections->isEmpty() ? 'No messages left to include.' : null;
        }
        if ($reason !== null) {
            $digest->update(['status' => 'skipped', 'skipped_reason' => $reason]);

            return 'skipped';
        }

        try {
            Mail::to($digest->email)->send(new ConversationDigest($digest, $sections));
            $digest->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null]);

            return 'sent';
        } catch (Throwable $e) {
            $digest->update(['status' => 'failed', 'last_error' => Str::limit($e->getMessage(), 480)]);
            report($e);

            return 'failed';
        }
    }

    /** Current access, checked at send time: unverified, suspended or removed recipients receive nothing. */
    private function accessProblem(Digest $digest): ?string
    {
        $user = $digest->user_id ? User::find($digest->user_id) : null;
        if (! $user || ! $user->isActive()) {
            return 'Recipient account is no longer active.';
        }
        if (mb_strtolower($user->email) !== mb_strtolower($digest->email)) {
            return 'Recipient email address has changed since this digest was planned.';
        }
        if ($digest->kind === 'firm') {
            return $this->firmRecipients()->contains('id', $user->id) ? null : 'Recipient is no longer an authorised firm recipient.';
        }
        $client = $digest->client_id ? Client::find($digest->client_id) : null;

        return $client && $this->contacts->portalUsers($client)->contains('id', $user->id)
            ? null
            : 'Recipient no longer has verified portal access for this client.';
    }

    private function row(DigestRun $run, string $kind, User $user, ?Client $client, Collection $messages, string $mode): array
    {
        return [
            'digest_run_id' => $run->id,
            'kind' => $kind,
            'user_id' => $user->id,
            'email' => $user->email,
            'client_id' => $client?->id,
            'mode' => $mode,
            'message_ids' => json_encode($messages->pluck('id')->values()->all()),
            'window_start' => $run->window_start,
            'window_end' => $run->cutoff_at,
            'status' => 'pending',
            'attempts' => 0,
            'dedupe_key' => implode(':', [$kind, $run->cutoff_at->format('YmdHi'), $user->id, $client?->id ?? 0]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
