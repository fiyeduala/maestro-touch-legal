<?php

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Managed redirects (imported WordPress URLs, retired plugin pages, manual entries).
 * The table is cached as one map; saving a Redirect flushes it. Application areas can never be redirected.
 */
class ApplyRedirects
{
    public const CACHE_KEY = 'redirects.map.v1';

    private const PROTECTED_PREFIXES = ['/admin', '/portal', '/livewire', '/up', '/build', '/filament', '/invitation', '/careers/application'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $path = Redirect::normalise($request->getPathInfo());
        if (self::isProtected($path)) {
            return $next($request);
        }

        $map = self::map();
        $key = isset($map[$path]) ? $path : (isset($map[rtrim($path, '/').'/']) ? rtrim($path, '/').'/' : (isset($map[rtrim($path, '/')]) ? rtrim($path, '/') : null));
        if ($key === null || $key === '') {
            return $next($request);
        }

        [$id, $to, $status] = $map[$key];
        DB::table('redirects')->where('id', $id)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);

        if ($status === 410 || $to === null) {
            abort(410);
        }

        $target = preg_match('#^https?://#i', $to) ? $to : url('/').'/'.ltrim($to, '/');
        if (str_ends_with($to, '/') && ! str_ends_with($target, '/')) {
            $target .= '/';
        }
        if ($query = $request->getQueryString()) {
            $target .= (str_contains($target, '?') ? '&' : '?').$query;
        }

        return redirect()->to($target, in_array($status, [301, 302, 307, 308], true) ? $status : 301);
    }

    /** Application areas (panel, portal, assets, tokens) that a managed redirect may never capture. */
    public static function isProtected(string $path): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{0:int,1:?string,2:int}> */
    public static function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('redirects')
            ->get(['id', 'from_path', 'to_path', 'status_code'])
            ->mapWithKeys(fn ($r) => [$r->from_path => [(int) $r->id, $r->to_path, (int) $r->status_code]])
            ->all());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
