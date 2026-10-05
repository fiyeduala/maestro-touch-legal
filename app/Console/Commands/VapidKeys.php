<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Prints the VAPID key pair that signs browser push notifications (D49). Generate once per site and keep it:
 * every subscription is bound to the public key it was made with, so a new pair means everyone has to turn
 * alerts on again. The keys are printed, never written anywhere; the private one belongs in .env only.
 */
class VapidKeys extends Command
{
    protected $signature = 'mtl:vapid-keys {--force : Print a new pair even though keys are already set}';

    protected $description = 'Generate the VAPID key pair for browser push notifications';

    public function handle(): int
    {
        if (trim((string) config('push.vapid.public_key')) !== '' && ! $this->option('force')) {
            $this->warn('Push keys are already set for this site. A new pair would switch off alerts for everyone who turned them on.');
            $this->line('Pass --force if that is really what you want.');

            return self::SUCCESS;
        }

        $keys = $this->generate();
        if (! $keys) {
            $this->error('This server cannot generate the key pair (PHP OpenSSL and the openssl command both failed).');
            $this->line('Run this command on another computer with PHP and copy the two lines into the server .env.');

            return self::FAILURE;
        }

        $this->info('Add these two lines to .env, then run: php artisan optimize');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->line('The public key is sent to browsers. Keep the private key in .env and nowhere else.');
        $this->line('Then check with: php artisan mtl:push-check');

        return self::SUCCESS;
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    private function generate(): ?array
    {
        try {
            return VAPID::createVapidKeys();
        } catch (Throwable) {
            // PHP's OpenSSL cannot make EC keys on some hosts (no openssl.cnf); try the openssl binary.
        }

        $pem = @shell_exec('openssl ecparam -name prime256v1 -genkey -noout 2>&1');
        if (! is_string($pem) || ! str_contains($pem, 'BEGIN EC PRIVATE KEY')) {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'vapid');
        try {
            file_put_contents($file, $pem);
            $text = @shell_exec('openssl ec -in '.escapeshellarg($file).' -text -noout 2>&1');
        } finally {
            @unlink($file);
        }
        if (! is_string($text)) {
            return null;
        }
        $private = $this->hex($text, 'priv');
        $public = $this->hex($text, 'pub');
        // Strip a leading zero byte some openssl versions print before the 32-byte scalar.
        if ($private !== null && strlen($private) === 33 && $private[0] === "\0") {
            $private = substr($private, 1);
        }
        if ($private === null || $public === null || strlen($private) !== 32 || strlen($public) !== 65 || $public[0] !== "\x04") {
            return null;
        }

        return ['publicKey' => $this->base64Url($public), 'privateKey' => $this->base64Url($private)];
    }

    private function hex(string $text, string $label): ?string
    {
        if (! preg_match('/'.$label.':\s*\n((?:\s*[0-9a-f]{2}(?::[0-9a-f]{2})*:?\s*\n)+)/i', $text, $m)) {
            return null;
        }
        $binary = @hex2bin((string) preg_replace('/[^0-9a-f]/i', '', $m[1]));

        return $binary === false ? null : $binary;
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
