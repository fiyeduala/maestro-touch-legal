<?php

namespace App\Models;

use App\Http\Middleware\ApplyRedirects;
use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $guarded = ['id', 'hits', 'last_hit_at'];

    protected function casts(): array
    {
        return ['last_hit_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Redirect $r) => $r->from_path = static::normalise($r->from_path));
        static::saved(fn () => ApplyRedirects::flush());
        static::deleted(fn () => ApplyRedirects::flush());
    }

    /** Stored form of a path: leading slash, decoded, lower-case, no query string. */
    public static function normalise(string $path): string
    {
        $path = '/'.ltrim(parse_url($path, PHP_URL_PATH) ?? '/', '/');

        return strtolower(rawurldecode($path));
    }
}
