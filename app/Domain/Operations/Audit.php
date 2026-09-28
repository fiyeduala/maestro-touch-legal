<?php

namespace App\Domain\Operations;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records who did what, to which record, from where. Secrets are redacted before storage.
 */
class Audit
{
    /** Keys whose values are never written to the audit log. */
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'remember_token', 'token', 'token_hash',
        'secret', 'secret_key', 'api_key', 'smtp_password', 'app_authentication_secret',
        'app_authentication_recovery_codes', 'applicant_token_hash', 'paystack_secret_key', 'webhook_secret',
    ];

    private static ?string $requestId = null;

    /**
     * @param  array<string, mixed>|null  $changes  e.g. ['before' => [...], 'after' => [...]]
     * @param  array<string, mixed>  $context  extra context merged with request details
     */
    public static function record(
        string $action,
        string $summary,
        ?Model $subject = null,
        ?array $changes = null,
        array $context = [],
        ?User $actor = null,
    ): AuditEvent {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditEvent::create([
            'occurred_at' => now(),
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? 'user' : ($request?->ip() ? 'guest' : 'system'),
            'actor_name' => $actor?->name,
            'actor_roles' => $actor?->activeRoleValues(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => Str::limit($summary, 490),
            'changes' => $changes ? self::redact($changes) : null,
            'context' => array_filter(self::redact($context) + [
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250) : null,
                'route' => $request?->route()?->getName(),
                'request_id' => self::requestId(),
                'console' => app()->runningInConsole() && ! app()->runningUnitTests() ? true : null,
            ], fn ($v) => $v !== null),
        ]);
    }

    /** Before/after diff of a model's dirty attributes, for use in `changes`. */
    public static function diff(Model $model, array $only = []): array
    {
        $after = $model->getDirty();
        if ($only) {
            $after = array_intersect_key($after, array_flip($only));
        }
        unset($after['updated_at']);
        $before = array_intersect_key($model->getOriginal(), $after);

        return ['before' => $before, 'after' => $after];
    }

    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $k = strtolower($key);
        foreach (self::REDACT as $needle) {
            if ($k === $needle || str_ends_with($k, '_'.$needle) || str_contains($k, 'password') || str_contains($k, 'secret')) {
                return true;
            }
        }

        return false;
    }

    private static function requestId(): string
    {
        return self::$requestId ??= (string) Str::uuid();
    }
}
