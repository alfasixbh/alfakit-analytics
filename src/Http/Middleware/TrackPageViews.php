<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Http\Middleware;

use AlfaSix\AlfakitAnalytics\Analytics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record page views on the server, without JavaScript: successful `GET` requests for
 * HTML pages and Inertia visits (not partial reloads, prefetches or JSON calls). The hit
 * is stored after the response was sent.
 *
 * ```php
 * Route::middleware('alfakit-analytics')->group(...);           // the default site
 * Route::middleware('alfakit-analytics:my-site-key')->group(...);
 * ```
 */
class TrackPageViews
{
    public function handle(Request $request, Closure $next, ?string $site = null): Response
    {
        $response = $next($request);

        if ($this->shouldTrack($request, $response)) {
            \Illuminate\Support\defer(static function () use ($request, $site): void {
                Analytics::pageview($request->fullUrl(), $site, $request);
            });
        }

        return $response;
    }

    protected function shouldTrack(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return false;
        }

        // Prefetches and Inertia partial reloads are not page views.
        foreach (['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (str_contains(strtolower((string) $request->headers->get($header)), 'prefetch')) {
                return false;
            }
        }

        if ($request->headers->has('X-Inertia')) {
            return ! $request->headers->has('X-Inertia-Partial-Data') && ! $request->headers->has('X-Inertia-Partial-Component');
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
