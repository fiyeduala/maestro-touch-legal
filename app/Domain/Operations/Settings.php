<?php

namespace App\Domain\Operations;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Typed key/value settings editable by full administrators.
 * Only keys declared in DEFINITIONS can be stored. Encrypted keys use APP_KEY
 * (with APP_PREVIOUS_KEYS during rotation), are masked in the UI and never audited in clear.
 */
class Settings
{
    private const CACHE_KEY = 'settings.all.v1';

    /** key => [default, encrypted?] */
    public const DEFINITIONS = [
        'site.title' => ['Maestro Touch Legal', false],
        'site.tagline' => ['Legal Solutions. Anywhere. Anytime.', false],
        'site.logo_path' => ['/wp-content/uploads/2025/08/mtl-blue.png', false],
        'site.logo_white_path' => ['/wp-content/uploads/2025/08/white.png', false],
        'site.logo_alt' => ['Maestro Touch Legal', false],
        'site.favicon_path' => ['/wp-content/uploads/2025/08/blue-1.png', false],
        'site.og_image_path' => ['/wp-content/uploads/2025/08/2152004777-1.jpg', false],
        'footer.text' => ['© {year} Maestro Touch Legal', false],
        'navigation.primary' => [[
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'About', 'url' => '/about/'],
            ['label' => 'Offerings', 'url' => '/offering/'],
            ['label' => 'Contact', 'url' => '/contact/'],
        ], false],
        'navigation.account_label' => ['Login/Register', false],
        'brand.primary' => ['#007BF8', false],
        'brand.ink' => ['#0F172A', false],
        'brand.body' => ['#364151', false],
        'brand.tint' => ['#E7F6FF', false],
        'contact.email' => [null, false],
        'contact.phone' => [null, false],
        'contact.address' => [null, false],
        'contact.whatsapp_number' => [null, false], // E.164 digits, e.g. 2348012345678
        'contact.whatsapp_message' => ['Hello Maestro Touch Legal, I would like to make a general enquiry.', false],
        'social.links' => [[], false], // [{label, url}]
        'mail.from_name' => ['Maestro Touch Legal', false],
        'mail.from_address' => [null, false],
        'mail.reply_to' => [null, false],
        'integrations.tawk_enabled' => [false, false],
        'integrations.tawk_property_id' => [null, false],
        'integrations.tawk_widget_id' => [null, false],
        'seo.google_site_verification' => ['M5H3PDZo-JzmJ6zNppKGN_AfoGk3BKzry32-oCGr0ac', false],
        'firm.timezone' => ['Africa/Lagos', false],
        'firm.nvn_url' => ['https://naijavirtualnotary.mtouchlegal.com/', false],
        'content.editors_can_publish' => [false, false],
        'content.comments_open' => [false, false], // new public comments (moderated); owner decision
        'notifications.admin_recipients' => [[], false], // explicitly configured addresses only
        // Bank-transfer instructions, one set per currency (see BankInstructions). Shown to clients, so not
        // encrypted; every change is audited like any other setting.
        'bank.ngn_bank_name' => [null, false],
        'bank.ngn_account_name' => [null, false],
        'bank.ngn_account_number' => [null, false],
        'bank.ngn_notes' => [null, false],
        'bank.usd_bank_name' => [null, false],
        'bank.usd_account_name' => [null, false],
        'bank.usd_account_number' => [null, false],
        'bank.usd_swift' => [null, false],
        'bank.usd_routing' => [null, false],
        'bank.usd_bank_address' => [null, false],
        'bank.usd_intermediary' => [null, false],
        'bank.usd_notes' => [null, false],
    ];

    private static ?array $loaded = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::assertDefined($key);
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : ($default ?? self::DEFINITIONS[$key][0]);
    }

    /** @return array<string, mixed> stored values only (decrypted) */
    public static function all(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $rows = Cache::remember(self::CACHE_KEY, 3600, fn () => DB::table('settings')->get(['key', 'value', 'is_encrypted'])
            ->map(fn ($r) => (array) $r)->all());

        $values = [];
        foreach ($rows as $r) {
            if (! isset(self::DEFINITIONS[$r['key']])) {
                continue;
            }
            $raw = $r['is_encrypted'] && $r['value'] !== null ? Crypt::decryptString($r['value']) : $r['value'];
            $values[$r['key']] = $raw === null ? null : json_decode($raw, true);
        }

        return self::$loaded = $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function set(array $values, ?User $actor = null): void
    {
        $changes = ['before' => [], 'after' => []];

        DB::transaction(function () use ($values, $actor, &$changes) {
            foreach ($values as $key => $value) {
                self::assertDefined($key);
                $encrypted = self::DEFINITIONS[$key][1];
                $old = self::get($key);
                if ($old === $value) {
                    continue;
                }
                $json = $value === null ? null : json_encode($value);
                DB::table('settings')->updateOrInsert(['key' => $key], [
                    'value' => $encrypted && $json !== null ? Crypt::encryptString($json) : $json,
                    'is_encrypted' => $encrypted,
                    'updated_by' => $actor?->id,
                    'updated_at' => now(),
                ]);
                $changes['before'][$key] = $encrypted ? '[encrypted]' : $old;
                $changes['after'][$key] = $encrypted ? '[encrypted, changed]' : $value;
            }
        });

        self::flush();

        if ($changes['after']) {
            Audit::record('settings.updated', 'Updated settings: '.implode(', ', array_keys($changes['after'])), changes: $changes, actor: $actor);
        }
    }

    public static function isEncrypted(string $key): bool
    {
        self::assertDefined($key);

        return self::DEFINITIONS[$key][1];
    }

    public static function flush(): void
    {
        self::$loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    private static function assertDefined(string $key): void
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new InvalidArgumentException("Unknown setting [$key].");
        }
    }
}
