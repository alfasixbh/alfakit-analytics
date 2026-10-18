<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

/**
 * The dashboard's filters: `dimension => value` pairs (`source => Google`). Values are
 * matched exactly; `*` is a wildcard for pages, and `(none)` matches empty values
 * (direct visits, unknown countries).
 */
final readonly class Filters
{
    /**
     * @param  array<string, string>  $filters
     */
    private function __construct(public array $filters) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Valid filters from user input: known dimensions, string values up to 512 characters.
     *
     * @param  array<array-key, mixed>|mixed  $input
     */
    public static function from(mixed $input): self
    {
        if (! is_array($input)) {
            return self::none();
        }

        $filters = [];

        foreach (Dimension::filterable() as $dimension) {
            $value = $input[$dimension] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $filters[$dimension] = mb_substr(trim((string) $value), 0, 512);
            }
        }

        return new self($filters);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->filters;
    }

    public function isEmpty(): bool
    {
        return $this->filters === [];
    }

    public function has(string $dimension): bool
    {
        return isset($this->filters[$dimension]);
    }

    public function get(string $dimension): ?string
    {
        return $this->filters[$dimension] ?? null;
    }

    public function with(string $dimension, string $value): self
    {
        return self::from([...$this->filters, $dimension => $value === '' ? Dimension::NONE : $value]);
    }

    public function without(string $dimension): self
    {
        $filters = $this->filters;
        unset($filters[$dimension]);

        return new self($filters);
    }

    /**
     * Filters on columns of the visit.
     *
     * @return array<string, string>
     */
    public function visitFilters(): array
    {
        return array_filter($this->filters, static fn (string $dimension): bool => Dimension::isVisitDimension($dimension), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Filters on the events of the visit (`page`, `event`, `goal`).
     *
     * @return array<string, string>
     */
    public function eventFilters(): array
    {
        return array_filter($this->filters, static fn (string $dimension): bool => in_array($dimension, Dimension::EVENT_FILTERS, true), ARRAY_FILTER_USE_KEY);
    }
}
