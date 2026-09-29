<?php

namespace App\Domain\Billing;

use App\Domain\RuleViolation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the Paystack API. Amounts are in the currency's minor unit (kobo / cents),
 * as Paystack expects. The secret key is read from config only and never logged or returned.
 */
class PaystackGateway
{
    /** Live keys are refused anywhere but production, so a staging or local copy can never take real money (D37). */
    public function configured(): bool
    {
        return filled(config('services.paystack.secret_key')) && ($this->mode() !== 'live' || app()->isProduction());
    }

    public function liveKeyRefused(): bool
    {
        return $this->mode() === 'live' && ! app()->isProduction();
    }

    /** 'test', 'live', 'unknown' (key does not look like a Paystack secret key) or null (not configured). */
    public function mode(): ?string
    {
        $key = (string) config('services.paystack.secret_key');

        return match (true) {
            $key === '' => null,
            str_starts_with($key, 'sk_test_') => 'test',
            str_starts_with($key, 'sk_live_') => 'live',
            default => 'unknown',
        };
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return array_values(array_intersect(array_map('strtoupper', (array) config('services.paystack.currencies')), ['NGN', 'USD']));
    }

    /** Online payment is offered for a currency only when Paystack is configured and enabled for it. */
    public function accepts(string $currency): bool
    {
        return $this->configured() && in_array(strtoupper($currency), $this->currencies(), true);
    }

    /** @return array{authorization_url: string, access_code: string, reference: string} */
    public function initialize(string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata): array
    {
        $data = $this->call('post', '/transaction/initialize', [
            'email' => $email,
            'amount' => $amountMinor,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);
        if (! is_string($data['authorization_url'] ?? null) || ! str_starts_with($data['authorization_url'], 'https://')) {
            throw new RuleViolation('Online payment could not be started. Please try again later or pay by bank transfer.');
        }

        return $data;
    }

    /** The provider's own record of a transaction; the only source of truth for "paid". */
    public function verify(string $reference): array
    {
        return $this->call('get', '/transaction/verify/'.rawurlencode($reference));
    }

    public function refund(string $reference, int $amountMinor): array
    {
        return $this->call('post', '/refund', ['transaction' => $reference, 'amount' => $amountMinor]);
    }

    /** Paystack signs the raw request body with the secret key (HMAC-SHA512, hex). */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.paystack.secret_key');
        if ($secret === '' || ! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower(trim($signature)));
    }

    private function call(string $method, string $path, array $payload = []): array
    {
        if (! $this->configured()) {
            throw new RuleViolation('Online payment is not set up.');
        }

        try {
            /** @var Response $response */
            $response = $this->http()->{$method}($path, $method === 'get' ? null : $payload);
        } catch (\Throwable $e) {
            Log::warning('Paystack request failed', ['path' => $this->safePath($path), 'error' => class_basename($e)]);
            throw new RuleViolation('The payment provider could not be reached. Please try again shortly.');
        }

        if (! $response->successful() || $response->json('status') !== true || ! is_array($response->json('data'))) {
            Log::warning('Paystack request refused', ['path' => $this->safePath($path), 'http' => $response->status(), 'message' => mb_substr((string) $response->json('message'), 0, 200)]);
            throw new RuleViolation('The payment provider did not accept the request. Please try again later.');
        }

        return $response->json('data');
    }

    private function http(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.paystack.base_url'), '/'))
            ->withToken((string) config('services.paystack.secret_key'))
            ->acceptJson()->asJson()->timeout(20)->connectTimeout(10);

        $bundle = config('services.paystack.ca_bundle');
        if (filled($bundle)) {
            $path = str_starts_with($bundle, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $bundle) ? $bundle : base_path($bundle);
            $request = $request->withOptions(['verify' => $path]);
        }

        return $request;
    }

    private function safePath(string $path): string
    {
        return preg_replace('#/transaction/verify/.*#', '/transaction/verify/{reference}', $path);
    }
}
