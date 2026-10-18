<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

/**
 * The parts of a page URL that are kept: hostname, path (without query string or
 * fragment) and the campaign parameters. Everything else in the query string is dropped.
 */
final readonly class PageUrl
{
    /** @var array<int, string> */
    public const UTM_PARAMETERS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * @param  array<string, string|null>  $utm
     */
    public function __construct(
        public string $hostname,
        public string $pathname,
        public array $utm,
    ) {}

    public static function parse(?string $url): ?self
    {
        if ($url === null || $url === '' || strlen($url) > 4096 || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $utm = [];

        foreach (self::UTM_PARAMETERS as $parameter) {
            $value = $query[$parameter] ?? null;
            $utm[$parameter] = is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 128) : null;
        }

        // `?ref=` and `?source=` are common untagged campaign links (Product Hunt, newsletters).
        if ($utm['utm_source'] === null) {
            foreach (['ref', 'source'] as $parameter) {
                $value = $query[$parameter] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $utm['utm_source'] = mb_substr(trim($value), 0, 128);

                    break;
                }
            }
        }

        return new self(strtolower($parts['host']), self::normalizePath((string) ($parts['path'] ?? '/')), $utm);
    }

    /**
     * `/blog/` → `/blog`, `` → `/`; at most 512 characters.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return mb_substr($path, 0, 512);
    }
}
