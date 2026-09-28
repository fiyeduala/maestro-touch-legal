<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer minor units (kobo / cents) only. Parsing works on the decimal string,
 * never through floats, and no currency is ever converted into another.
 */
final class Money
{
    public const CURRENCIES = ['NGN' => '₦', 'USD' => '$'];

    /** @return array<string, string> */
    public static function currencyOptions(): array
    {
        return ['NGN' => 'NGN (₦)', 'USD' => 'USD ($)'];
    }

    public static function assertCurrency(string $currency): string
    {
        $currency = strtoupper($currency);
        if (! array_key_exists($currency, self::CURRENCIES)) {
            throw new InvalidArgumentException("Unsupported currency {$currency}.");
        }

        return $currency;
    }

    /** "1,250.5" → 125050. Rejects negatives, more than two decimals and anything non-numeric. */
    public static function parse(string|int|null $amount): int
    {
        if (is_int($amount)) {
            throw new InvalidArgumentException('Pass major-unit amounts as strings to avoid ambiguity.');
        }
        $clean = str_replace([',', ' '], '', trim((string) $amount));
        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Invalid amount \"{$amount}\". Use digits with up to two decimals.");
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** 125050 → "1,250.50" */
    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 125050, NGN → "₦1,250.50" */
    public static function format(int $minor, string $currency): string
    {
        $symbol = self::CURRENCIES[strtoupper($currency)] ?? strtoupper($currency).' ';
        $decimal = self::toDecimal($minor);

        return str_starts_with($decimal, '-') ? '-'.$symbol.substr($decimal, 1) : $symbol.$decimal;
    }
}
