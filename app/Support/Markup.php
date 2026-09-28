<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Small, safe inline formatting for editable page copy. Everything is escaped
 * first; only the *word* highlight convention (brand-blue words in headings)
 * is turned into markup. Editors never enter raw HTML into page templates.
 */
class Markup
{
    public static function hl(?string $text): HtmlString
    {
        $escaped = e((string) $text);

        return new HtmlString(preg_replace('/\*([^*\n]+)\*/u', '<span class="hl">$1</span>', $escaped));
    }

    /** Removes the highlight markers, for titles, meta tags and plain text. */
    public static function plain(?string $text): string
    {
        return preg_replace('/\*([^*\n]+)\*/u', '$1', (string) $text);
    }

    /** Paragraphs from plain text separated by blank lines; each is escaped. */
    public static function paragraphs(?string $text): HtmlString
    {
        $parts = preg_split('/\R{2,}/u', trim((string) $text)) ?: [];

        return new HtmlString(collect($parts)
            ->filter(fn ($p) => trim($p) !== '')
            ->map(fn ($p) => '<p>'.nl2br(e(trim($p)), false).'</p>')
            ->implode("\n"));
    }

    /** Local path (e.g. /about/) or http(s) URL only; anything else becomes "#". */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '#';
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return url($url);
        }

        return preg_match('#^https?://#i', $url) ? $url : '#';
    }
}
