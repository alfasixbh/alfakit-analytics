<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use Illuminate\Database\Query\Builder;

/**
 * Portable SQL helpers (MySQL, PostgreSQL, SQLite).
 */
final class Sql
{
    /**
     * `$column` equals `$pattern`, or matches it with `*` as a wildcard (`/blog/*`).
     */
    public static function wherePattern(Builder $query, string $column, string $pattern): Builder
    {
        if (! str_contains($pattern, '*')) {
            return $query->where($column, $pattern);
        }

        $like = str_replace(['!', '%', '_', '*'], ['!!', '!%', '!_', '%'], $pattern);

        return $query->whereRaw($query->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$like]);
    }

    public static function matchesPattern(string $pattern, string $value): bool
    {
        if (! str_contains($pattern, '*')) {
            return $pattern === $value;
        }

        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#u';

        return preg_match($regex, $value) === 1;
    }
}
