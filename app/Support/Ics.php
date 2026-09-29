<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Minimal RFC 5545 calendar invitation (one event, UTC times, CRLF line endings, folded lines). */
final class Ics
{
    public static function event(
        string $uid,
        int $sequence,
        Carbon $start,
        Carbon $end,
        string $summary,
        string $description,
        ?string $location = null,
        bool $cancelled = false,
    ): string {
        $format = fn (Carbon $time) => $time->copy()->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Maestro Touch Legal//Consultations//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:'.($cancelled ? 'CANCEL' : 'PUBLISH'),
            'BEGIN:VEVENT',
            'UID:'.self::escape($uid),
            'SEQUENCE:'.$sequence,
            'DTSTAMP:'.$format(now()),
            'DTSTART:'.$format($start),
            'DTEND:'.$format($end),
            'SUMMARY:'.self::escape($summary),
            'DESCRIPTION:'.self::escape($description),
        ];
        if ($location) {
            $lines[] = 'LOCATION:'.self::escape($location);
        }
        $lines[] = 'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Lines longer than 75 octets continue on the next line after a space. */
    private static function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            // Do not split a multibyte UTF-8 character.
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
        }

        return $out.$line;
    }
}
