<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Models\Site;

/**
 * The anonymous visitor id: the first 64 bits of an HMAC-SHA256 of the site, IP address
 * and user agent, keyed with the daily salt. The same browser gets the same id on one site
 * for at most 24 hours; the inputs are never stored.
 */
final class VisitorHash
{
    public static function make(Site $site, ?string $ip, ?string $userAgent, ?string $salt = null): string
    {
        $data = implode("\n", [(string) $site->getKey(), (string) $site->key, (string) $ip, (string) $userAgent]);

        return substr(hash_hmac('sha256', $data, $salt ?? Salts::current()), 0, 16);
    }
}
