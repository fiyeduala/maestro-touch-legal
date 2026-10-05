<?php

namespace App\Support\Video;

use App\Domain\Operations\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Daily.co rooms for meetings and video consultations (D50), on the pattern proven in Naija Virtual Notary.
 *
 * Rooms are private: the room address alone opens nothing. A meeting token, minted here only for someone
 * the caller has already authorised, is the only way in, and it expires with the room.
 *
 * Not recorded. Daily cannot be told "enable_recording: false", so recording is off by omission: never
 * asked for on the room or the token. Every room is read back, and a recording setting (a default in the
 * Daily dashboard) is logged and audited as video.recording_enabled_unexpectedly.
 *
 * Nothing here throws. A missing key or an unreachable Daily comes back as a reason the join page can show.
 */
class DailyVideo
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNREACHABLE = 'unreachable';

    public function configured(): bool
    {
        return $this->apiKey() !== '' && $this->domain() !== '';
    }

    /**
     * A room for $subject that accepts joins until $until, reusing $existing when Daily still has it.
     *
     * @return array{room: ?string, error: ?string}
     */
    public function ensureRoom(?string $existing, string $prefix, Model $subject, Carbon $until): array
    {
        if (! $this->configured()) {
            return ['room' => null, 'error' => self::NOT_CONFIGURED];
        }
        $expiry = $until->getTimestamp();

        if ($existing) {
            $room = $this->send(fn (PendingRequest $r) => $r->get($this->endpoint("rooms/{$existing}")));
            if ($room === null) {
                return ['room' => null, 'error' => self::UNREACHABLE];
            }
            if ($room->successful()) {
                $this->auditRecording($subject, $existing, $room->json());
                if ((int) data_get($room->json(), 'config.exp', 0) >= $expiry) {
                    return ['room' => $existing, 'error' => null];
                }
                $extended = $this->send(fn (PendingRequest $r) => $r->post($this->endpoint("rooms/{$existing}"), ['properties' => ['exp' => $expiry]]));

                return $extended?->successful() ? ['room' => $existing, 'error' => null] : ['room' => null, 'error' => self::UNREACHABLE];
            }
            if ($room->status() !== 404) {
                // A bad key or a rate limit is not fixed by creating another room.
                $this->failed('room.lookup', $room, ['room' => $existing]);

                return ['room' => null, 'error' => self::UNREACHABLE];
            }
        }

        $name = $prefix.'-'.$subject->getKey().'-'.Str::lower(Str::random(10));
        $response = $this->send(fn (PendingRequest $r) => $r->post($this->endpoint('rooms'), [
            'name' => $name,
            'privacy' => 'private',
            'properties' => [
                'exp' => $expiry,
                // The expiry only closes the door to new joins; a call that runs over is not cut off.
                'eject_at_room_exp' => false,
                'enable_prejoin_ui' => (bool) config('video.daily.prejoin_ui', true),
                // Private and no knocking: a meeting token is the only way in.
                'enable_knocking' => false,
                // Anything worth keeping belongs in the matter conversation, not an in-call chat that disappears.
                'enable_chat' => false,
                'enable_screenshare' => true,
                'max_participants' => max(2, (int) config('video.daily.max_participants', 10)),
                // enable_recording is deliberately absent. See the class comment.
            ],
        ]));
        if ($response === null || ! $response->successful()) {
            $this->failed('room.create', $response, ['subject' => $subject::class.'#'.$subject->getKey()]);

            return ['room' => null, 'error' => self::UNREACHABLE];
        }
        $this->auditRecording($subject, $name, $response->json());

        return ['room' => $name, 'error' => null];
    }

    /** A token for one person in one room, valid until $until. Null when Daily could not be reached. */
    public function token(string $room, string $userId, string $userName, bool $owner, Carbon $until): ?string
    {
        $response = $this->send(fn (PendingRequest $r) => $r->post($this->endpoint('meeting-tokens'), [
            'properties' => [
                'room_name' => $room,
                'user_name' => Str::limit(trim($userName) ?: 'Participant', 40, ''),
                'user_id' => Str::limit($userId, 36, ''),
                'is_owner' => $owner,
                'exp' => $until->getTimestamp(),
                'enable_screenshare' => true,
                // enable_recording is deliberately absent. See the class comment.
            ],
        ]));
        if ($response === null || ! $response->successful()) {
            $this->failed('token.create', $response, ['room' => $room]);

            return null;
        }
        $token = $response->json('token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function deleteRoom(string $room): bool
    {
        return (bool) $this->send(fn (PendingRequest $r) => $r->delete($this->endpoint("rooms/{$room}")))?->successful();
    }

    /** For the deployment check: what Daily says about the API key's domain. */
    public function domainInfo(): ?Response
    {
        return $this->send(fn (PendingRequest $r) => $r->get($this->endpoint('')));
    }

    /**
     * For the deployment check: create a throwaway private room, read it back, mint a token for it, delete it.
     *
     * @return list<array{step: string, ok: bool, detail: string}>
     */
    public function probe(): array
    {
        $steps = [];
        $info = $this->domainInfo();
        $steps[] = ['step' => 'API key', 'ok' => (bool) $info?->successful(),
            'detail' => $info === null ? 'Daily could not be reached' : ($info->successful() ? 'accepted (domain '.($info->json('domain_name') ?? '?').')' : 'HTTP '.$info->status().' '.Str::limit($info->body(), 200))];
        if (! $info?->successful()) {
            return $steps;
        }

        $name = 'check-'.Str::lower(Str::random(10));
        $room = $this->send(fn (PendingRequest $r) => $r->post($this->endpoint('rooms'), [
            'name' => $name, 'privacy' => 'private', 'properties' => ['exp' => now()->addMinutes(10)->getTimestamp()],
        ]));
        $steps[] = ['step' => 'Create a private room', 'ok' => (bool) $room?->successful(), 'detail' => $room?->successful() ? $name : 'HTTP '.($room?->status() ?? 'no response')];
        if (! $room?->successful()) {
            return $steps;
        }
        $recording = data_get($room->json(), 'config.enable_recording');
        $steps[] = ['step' => 'Recording off', 'ok' => blank($recording),
            'detail' => blank($recording) ? 'not enabled' : 'Daily reports recording "'.json_encode($recording).'": turn it off in the Daily dashboard'];
        $token = $this->token($name, 'check', 'Set-up check', false, now()->addMinutes(10));
        $steps[] = ['step' => 'Mint a join token', 'ok' => $token !== null, 'detail' => $token ? 'ok' : 'failed (see the log)'];
        $deleted = $this->deleteRoom($name);
        $steps[] = ['step' => 'Delete the test room', 'ok' => $deleted, 'detail' => $deleted ? 'ok' : 'failed; delete '.$name.' in the Daily dashboard'];

        return $steps;
    }

    /** The room's address. Useless without a token: rooms are private. */
    public function roomUrl(string $room): string
    {
        return 'https://'.$this->domain().'/'.$room;
    }

    /** Accepts 'yourteam' or 'yourteam.daily.co'; always returns a host name. */
    public function domain(): string
    {
        $domain = trim((string) config('video.daily.domain', ''), " \t\n\r\0\x0B/");
        if ($domain === '') {
            return '';
        }
        $domain = (string) preg_replace('#^https?://#i', '', $domain);

        return str_contains($domain, '.') ? $domain : $domain.'.daily.co';
    }

    private function send(callable $call): ?Response
    {
        try {
            return $call($this->client());
        } catch (ConnectionException $e) {
            Log::warning('[daily] could not reach Daily: '.$e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[daily] request failed: '.$e->getMessage());
        }

        return null;
    }

    private function client(): PendingRequest
    {
        $timeout = max(2, (int) config('video.daily.timeout', 6));

        return Http::withToken($this->apiKey())->acceptJson()->asJson()
            ->timeout($timeout)->connectTimeout(min(4, $timeout))
            // Only a dropped connection is retried; retrying a 4xx could create duplicate rooms.
            ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('video.daily.api_url', 'https://api.daily.co/v1'), '/').'/'.ltrim($path, '/');
    }

    private function auditRecording(Model $subject, string $room, ?array $payload): void
    {
        $setting = data_get($payload, 'config.enable_recording');
        if (blank($setting)) {
            return;
        }
        Log::warning("[daily] room {$room} reports recording enabled (".json_encode($setting).'). The firm never requests recording; check the Daily dashboard defaults.');
        Audit::record('video.recording_enabled_unexpectedly', "Daily reports recording enabled on room {$room}", $subject,
            context: ['room' => $room, 'enable_recording' => $setting]);
    }

    private function failed(string $stage, ?Response $response, array $context = []): void
    {
        Log::error("[daily] {$stage} failed", $context + [
            'status' => $response?->status(),
            'error' => $response ? Str::limit((string) $response->body(), 500) : 'no response',
        ]);
    }

    private function apiKey(): string
    {
        return trim((string) config('video.daily.api_key', ''));
    }
}
