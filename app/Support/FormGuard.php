<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spam screening for the public forms (D48), in order: a hidden field only bots fill, a minimum time between loading
 * the form and sending it, a cap on web links, and Cloudflare Turnstile once its keys are set. Bots are answered as
 * if they had succeeded; anything a person could trip gets a message they can act on.
 */
final class FormGuard
{
    public const HONEYPOT = 'company_website';

    public const STAMP = 'form_started';

    private const LINK = '~https?://|www\.|\[url|<a\s~i';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** When the form was shown, encrypted so it cannot be forged. */
    public static function stamp(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /** The Turnstile site key, only when both keys are set (otherwise the check is off). */
    public static function turnstileSiteKey(): ?string
    {
        $site = config('services.turnstile.site_key');

        return filled($site) && filled(config('services.turnstile.secret_key')) ? (string) $site : null;
    }

    /**
     * @param  list<string>  $textFields  free-text fields whose web links count towards $maxLinks
     * @return bool true when the submission looks automated and should be dropped without saying so
     *
     * @throws ValidationException when a person could have tripped the check
     */
    public static function rejects(Request $request, array $textFields = [], int $maxLinks = 1): bool
    {
        if ($request->filled(self::HONEYPOT)) {
            return true;
        }

        $minimum = (int) config('forms.min_seconds');
        if ($minimum > 0) {
            $started = self::startedAt((string) $request->input(self::STAMP));
            if ($started === null) {
                throw ValidationException::withMessages(['form' => 'This form had expired. Please check your details and send it again.']);
            }
            if (now()->getTimestamp() - $started < $minimum) {
                return true;
            }
        }

        $links = 0;
        $first = null;
        foreach ($textFields as $field) {
            $value = $request->input($field);
            $count = is_string($value) ? preg_match_all(self::LINK, $value) : 0;
            if ($count > 0) {
                $links += $count;
                $first ??= $field;
            }
        }
        if ($links > $maxLinks) {
            throw ValidationException::withMessages([$first => $maxLinks === 0
                ? 'Web addresses are not accepted here.'
                : "Please include no more than {$maxLinks} web ".($maxLinks === 1 ? 'link' : 'links').'. You can send others once we reply.']);
        }

        if (self::turnstileSiteKey() !== null && ! self::passesTurnstile($request)) {
            throw ValidationException::withMessages(['form' => 'Please complete the "verify you are human" check and send the form again.']);
        }

        return false;
    }

    private static function startedAt(string $stamp): ?int
    {
        if ($stamp === '') {
            return null;
        }
        try {
            $time = Crypt::decryptString($stamp);
        } catch (DecryptException) {
            return null;
        }

        return ctype_digit($time) && (int) $time <= now()->getTimestamp() + 60 ? (int) $time : null;
    }

    private static function passesTurnstile(Request $request): bool
    {
        $token = (string) $request->input('cf-turnstile-response');
        if ($token === '') {
            return false;
        }
        try {
            return Http::asForm()->timeout(10)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ])->json('success') === true;
        } catch (Throwable $e) {
            Log::warning('Turnstile check could not be completed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
