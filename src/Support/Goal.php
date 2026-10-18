<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use Illuminate\Database\Query\Builder;

/**
 * A conversion goal of a site: a page (`/checkout/thanks`, `/blog/*`) or a custom event
 * (`Signup`). Conversions are the visitors who reached it.
 */
final readonly class Goal
{
    public const PAGE = 'page';

    public const EVENT = 'event';

    public function __construct(
        public string $name,
        public string $type,
        public string $match,
    ) {}

    public static function page(string $path, ?string $name = null): self
    {
        $path = PageUrl::normalizePath($path);

        return new self($name ?? $path, self::PAGE, $path);
    }

    public static function event(string $event, ?string $name = null): self
    {
        return new self($name ?? $event, self::EVENT, $event);
    }

    /**
     * @param  array<array-key, mixed>  $goal  `{name?, type, match}` as stored on the site
     */
    public static function fromArray(array $goal): ?self
    {
        $type = $goal['type'] ?? null;
        $match = $goal['match'] ?? null;
        $name = $goal['name'] ?? null;

        if (! in_array($type, [self::PAGE, self::EVENT], true) || ! is_string($match) || trim($match) === '') {
            return null;
        }

        $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null;

        return $type === self::PAGE ? self::page($match, $name) : self::event(mb_substr(trim($match), 0, 64), $name);
    }

    /**
     * @return array{name: string, type: string, match: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'match' => $this->match];
    }

    public function matchesPath(string $path): bool
    {
        return $this->type === self::PAGE && Sql::matchesPattern($this->match, $path);
    }

    /**
     * Constrain an events query (`$alias` = the events table) to this goal's hits.
     */
    public function constrain(Builder $query, string $alias = 'e'): Builder
    {
        if ($this->type === self::EVENT) {
            return $query->where("{$alias}.name", $this->match);
        }

        $query->where("{$alias}.name", 'pageview');

        return Sql::wherePattern($query, "{$alias}.pathname", $this->match);
    }
}
