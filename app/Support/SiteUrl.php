<?php

namespace App\Support;

/**
 * Absolute public URLs in the WordPress style the site has always used: paths end in "/".
 * Laravel's url() trims trailing slashes, so canonical/sitemap/feed links are built here.
 */
class SiteUrl
{
    public static function to(string $path): string
    {
        $path = '/'.trim($path, '/');

        return rtrim(url('/'), '/').($path === '/' ? '/' : $path.'/');
    }
}
