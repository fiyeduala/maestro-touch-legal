<?php

namespace App\Domain\Operations;

/**
 * The firm's bank-transfer instructions, configured separately for NGN and USD in Settings.
 * A currency offers bank transfer only when its required details are all present (spec §10).
 */
class BankInstructions
{
    public const CURRENCIES = ['NGN', 'USD'];

    /** Fields that must be filled before transfers in that currency are offered. */
    private const REQUIRED = [
        'NGN' => ['bank_name', 'account_name', 'account_number'],
        'USD' => ['bank_name', 'account_name', 'account_number', 'swift'],
    ];

    private const FIELDS = [
        'NGN' => ['bank_name', 'account_name', 'account_number', 'notes'],
        'USD' => ['bank_name', 'account_name', 'account_number', 'swift', 'routing', 'bank_address', 'intermediary', 'notes'],
    ];

    /** @return array<string, string>|null null when transfers in this currency are not configured */
    public static function for(string $currency): ?array
    {
        $currency = strtoupper($currency);
        if (! isset(self::FIELDS[$currency])) {
            return null;
        }

        $details = [];
        foreach (self::FIELDS[$currency] as $field) {
            $value = trim((string) Settings::get('bank.'.strtolower($currency).'_'.$field));
            if ($value !== '') {
                $details[$field] = $value;
            }
        }
        foreach (self::REQUIRED[$currency] as $field) {
            if (! isset($details[$field])) {
                return null;
            }
        }

        return $details;
    }

    public static function available(string $currency): bool
    {
        return self::for($currency) !== null;
    }
}
