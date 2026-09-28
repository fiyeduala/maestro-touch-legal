<?php

namespace App\Support;

/**
 * Absolute public URLs in the WordPress style the site has always used: paths end in "/".
 * Laravel's url() trims trailing slashes, so canonical/sitemap/feed links are built here.
 */
class SiteUrl
{
    /** Lower-case words joined by single hyphens, as WordPress generated them. */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const SLUG_HELP = 'Use lower-case letters, numbers and single hyphens only, e.g. land-title-in-lagos.';

    public static function to(string $path): string
    {
        $path = '/'.trim($path, '/');

        return rtrim(url('/'), '/').($path === '/' ? '/' : $path.'/');
    }
}
