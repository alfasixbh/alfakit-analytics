<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

/**
 * The dashboard's query string as the user sees it: the current request's on page loads,
 * lazy loads and polls (Inertia reloads of the page URL), the referring page's on internal
 * endpoints (actions), like `DashboardFilters`. It is user input: `Report` validates it.
 */
final class PageQuery
{
    /**
     * @return array<array-key, mixed>
     */
    public static function get(): array
    {
        $request = request();

        if (! str_contains($request->path(), '_alfakit/')) {
            return $request->query();
        }

        $previous = url()->previous();

        if ($previous === '' || ! str_starts_with($previous, $request->getSchemeAndHttpHost())) {
            return [];
        }

        $query = [];
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);

        return $query;
    }
}
