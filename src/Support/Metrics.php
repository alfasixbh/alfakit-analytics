<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

/**
 * Additive metrics of a period or a row. Visitors are unique per day (the visitor hash
 * changes every day), so the visitors of a range are the sum of each day's.
 */
final class Metrics
{
    public function __construct(
        public int $visitors = 0,
        public int $visits = 0,
        public int $pageviews = 0,
        public int $events = 0,
        public int $bounces = 0,
        public int $duration = 0,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row  A query row with the metric columns
     */
    public static function fromRow(object|array $row): self
    {
        $row = (array) $row;

        return new self(
            (int) ($row['visitors'] ?? 0),
            (int) ($row['visits'] ?? 0),
            (int) ($row['pageviews'] ?? 0),
            (int) ($row['events'] ?? 0),
            (int) ($row['bounces'] ?? 0),
            (int) ($row['duration'] ?? 0),
        );
    }

    public function add(self $other): self
    {
        return new self(
            $this->visitors + $other->visitors,
            $this->visits + $other->visits,
            $this->pageviews + $other->pageviews,
            $this->events + $other->events,
            $this->bounces + $other->bounces,
            $this->duration + $other->duration,
        );
    }

    /** Percentage of visits with a single page view and no interaction. */
    public function bounceRate(): ?float
    {
        return $this->visits === 0 ? null : round($this->bounces / $this->visits * 100, 1);
    }

    /** Average visit duration in seconds. */
    public function visitDuration(): ?int
    {
        return $this->visits === 0 ? null : (int) round($this->duration / $this->visits);
    }

    public function viewsPerVisit(): ?float
    {
        return $this->visits === 0 ? null : round($this->pageviews / $this->visits, 2);
    }

    public function value(string $metric): float|int|null
    {
        return match ($metric) {
            'visitors' => $this->visitors,
            'visits' => $this->visits,
            'pageviews' => $this->pageviews,
            'events' => $this->events,
            'bounce_rate' => $this->bounceRate(),
            'visit_duration' => $this->visitDuration(),
            'views_per_visit' => $this->viewsPerVisit(),
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'visitors' => $this->visitors,
            'visits' => $this->visits,
            'pageviews' => $this->pageviews,
            'events' => $this->events,
            'bounces' => $this->bounces,
            'duration' => $this->duration,
        ];
    }

    /**
     * The change from `$previous` in percent (null when there is nothing to compare with).
     */
    public static function change(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
