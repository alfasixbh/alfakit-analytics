<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Contracts;

use Illuminate\Http\Request;

/**
 * Resolves the country of a hit (an ISO 3166-1 alpha-2 code such as `BH`), or null. The IP
 * address is only passed in memory and never stored. Bind your implementation (a MaxMind
 * GeoLite2 reader, a CDN header…) in a service provider:
 *
 * ```php
 * $this->app->bind(ResolvesCountry::class, MaxMindCountryResolver::class);
 * ```
 */
interface ResolvesCountry
{
    public function resolve(?string $ip, ?Request $request): ?string;
}
