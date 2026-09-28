<?php

namespace App\Support;

/**
 * Stable SHA-256 of structured content. Object keys are sorted recursively so the hash does not
 * change when a database JSON column reorders keys (MySQL's native JSON type does).
 */
final class CanonicalJson
{
    public static function hash(mixed $data): string
    {
        return hash('sha256', json_encode(self::sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function sort(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        $data = array_map(self::sort(...), $data);
        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }
}
