<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics;

use AlfaSix\AlfakitAnalytics\Models\Event;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Support\Hit;
use AlfaSix\AlfakitAnalytics\Support\Recorder;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;

/**
 * The public side of the analytics plugin.
 *
 * ```php
 * // Server-side events (attributed to the visitor of the current request):
 * Analytics::track('Signup', ['plan' => 'pro']);
 *
 * // A page view without JavaScript (or use the `alfakit-analytics` middleware):
 * Analytics::pageview();
 *
 * // The snippet, in a Blade layout (or `@alfakitAnalytics`):
 * {{ Analytics::script() }}
 * ```
 *
 * Without a `$site`, the site of `alfakit-analytics.default_site` (its key) is used.
 */
final class Analytics
{
    public const ROUTE_COLLECT = 'alfakit-analytics.collect';

    public const ROUTE_SCRIPT = 'alfakit-analytics.script';

    public const ROUTE_SHARE = 'alfakit-analytics.share';

    /**
     * Record a custom event for the visitor of `$request` (the current request by default).
     *
     * @param  array<string, scalar|null>  $properties
     */
    public static function track(string $event, array $properties = [], Site|string|null $site = null, ?Request $request = null, ?string $url = null): ?Event
    {
        return self::recordFor($site, $request, $event, $url, $properties);
    }

    /**
     * Record a page view of `$url` (the current URL by default) for the visitor of `$request`.
     */
    public static function pageview(?string $url = null, Site|string|null $site = null, ?Request $request = null): ?Event
    {
        return self::recordFor($site, $request, Event::PAGEVIEW, $url, []);
    }

    /**
     * Record a hit you built yourself.
     */
    public static function record(Site $site, Hit $hit): ?Event
    {
        return app(Recorder::class)->record($site, $hit);
    }

    /**
     * The site with this key, the given site, or the default site; null when there is none.
     */
    public static function site(Site|string|null $site = null): ?Site
    {
        if ($site instanceof Site) {
            return $site;
        }

        $key = $site ?? config('alfakit-analytics.default_site');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return Site::query()->where('key', $key)->first();
    }

    /**
     * The `<script>` tag of the tracking snippet (under 2 KB, no cookies, no storage).
     *
     * @param  array<string, string|bool>  $attributes  Extra attributes (`nonce`, `data-local` to count localhost…)
     */
    public static function script(Site|string|null $site = null, array $attributes = []): HtmlString
    {
        $key = $site instanceof Site ? $site->key : ($site ?? config('alfakit-analytics.default_site'));

        if (! is_string($key) || $key === '') {
            return new HtmlString('');
        }

        $html = '<script defer src="'.e(self::scriptUrl()).'" data-site="'.e($key).'" data-api="'.e(self::endpointUrl()).'"';

        foreach ($attributes as $name => $value) {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_:-]*$/', $name) !== 1 || $value === false) {
                continue;
            }

            $html .= $value === true ? ' '.$name : ' '.$name.'="'.e($value).'"';
        }

        return new HtmlString($html.'></script>');
    }

    public static function scriptUrl(): string
    {
        return route(self::ROUTE_SCRIPT, absolute: false);
    }

    public static function endpointUrl(): string
    {
        return route(self::ROUTE_COLLECT, absolute: false);
    }

    /**
     * The signed, read-only link of a site's shared dashboard (null unless sharing is on).
     */
    public static function shareUrl(Site $site, ?CarbonInterface $expiresAt = null): ?string
    {
        if (! $site->is_shared || $site->share_token === null) {
            return null;
        }

        $parameters = ['site' => $site->getKey(), 'token' => $site->share_token];

        return $expiresAt === null
            ? URL::signedRoute(self::ROUTE_SHARE, $parameters)
            : URL::temporarySignedRoute(self::ROUTE_SHARE, $expiresAt, $parameters);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    private static function recordFor(Site|string|null $site, ?Request $request, string $name, ?string $url, array $properties): ?Event
    {
        $site = self::site($site);
        // Queued jobs and commands have no visitor: their events are recorded without one.
        $request ??= app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        if ($site === null) {
            return null;
        }

        $hit = $request === null
            ? new Hit(name: $name, url: $url ?? (string) config('app.url'), properties: $properties, isServerSide: true)
            : Hit::fromRequest($request, $name, $url, $request->headers->get('referer'), $properties);

        return self::record($site, $hit);
    }
}
