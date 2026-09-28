<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 301s "/about" to "/about/" on public HTML routes, matching the live WordPress URLs. */
class AddTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if ($request->isMethodSafe() && $path !== '/' && ! str_ends_with($path, '/') && ! pathinfo($path, PATHINFO_EXTENSION)) {
            $query = $request->getQueryString();

            return redirect()->to($request->getSchemeAndHttpHost().$request->getBaseUrl().$path.'/'.($query ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
