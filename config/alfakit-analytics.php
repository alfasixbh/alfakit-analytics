<?php

declare(strict_types=1);

return [

    /*
     * The public routes: `POST /{prefix}/event` (collection), `GET /{prefix}/script.js` (the
     * tracking snippet) and `GET /{prefix}/share/{site}` (signed, read-only shared
     * dashboards). The collection endpoint is stateless: no session, no cookies, no CSRF.
     */
    'route_prefix' => 'alfakit-analytics',

    // Extra middleware for the public routes (the collection endpoint is rate limited below).
    'middleware' => [],

    // Requests per minute the collection endpoint accepts from one IP address.
    'rate_limit' => 120,

    /*
     * The site `Analytics::track()`, `@alfakitAnalytics` and the `alfakit-analytics`
     * middleware use when they are not given one (its public site key).
     */
    'default_site' => null,

    // Drop hits from browsers that send `DNT: 1` or `Sec-GPC: 1` (the snippet never sends them).
    'respect_do_not_track' => true,

    // Minutes of inactivity after which the next hit starts a new visit.
    'session_timeout' => 30,

    // Minutes a visitor counts as "now" on the realtime card.
    'realtime_window' => 5,

    /*
     * How long raw events and visits are kept (days). Daily rollups keep the dashboard's
     * unfiltered numbers after that; filters and hourly charts need raw data.
     */
    'raw_retention_days' => 60,

    // How long daily rollups are kept (days); null keeps them forever.
    'rollup_retention_days' => null,

    // Register `alfakit-analytics:aggregate` on the scheduler (hourly).
    'schedule' => true,

    /*
     * Country of a hit. A request header set by your CDN or proxy (`CF-IPCountry` on
     * Cloudflare, `CloudFront-Viewer-Country` on CloudFront), read when no
     * `ResolvesCountry` implementation is bound in the container.
     */
    'country_header' => null,

    // Extra user-agent fragments treated as bots (case-insensitive, matched as plain text).
    'bot_patterns' => [],

    // Paths that are never recorded (`*` matches anything): your panel, health checks…
    'exclude_paths' => [],

    // Limits of custom event properties.
    'max_properties' => 30,

];
