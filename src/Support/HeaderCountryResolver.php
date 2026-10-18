<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Contracts\ResolvesCountry;
use Illuminate\Http\Request;

/**
 * The default resolver: the country your CDN or proxy put in a request header
 * (`alfakit-analytics.country_header`, e.g. `CF-IPCountry`), or none.
 */
class HeaderCountryResolver implements ResolvesCountry
{
    public function resolve(?string $ip, ?Request $request): ?string
    {
        $header = config('alfakit-analytics.country_header');

        if (! is_string($header) || $header === '' || $request === null) {
            return null;
        }

        return self::normalize($request->header($header));
    }

    /**
     * An uppercase two-letter code, or null (`XX`, `T1` and other placeholders are dropped).
     */
    public static function normalize(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && ! in_array($code, ['XX', 'ZZ', 'EU', 'AP'], true) ? $code : null;
    }
}
