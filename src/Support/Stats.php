<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Models\DailyStat;
use AlfaSix\AlfakitAnalytics\Models\Site;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's numbers for a site, a period and filters. Without filters, days that
 * were aggregated are read from the daily rollups and the others (today, days not yet
 * aggregated) from raw data; filtered queries always read raw data, so they only cover
 * the raw retention window.
 */
final class Stats
{
    /** @var array<int, string>|null */
    private ?array $rolledUpDates = null;

    private ?RawStats $raw = null;

    public function __construct(
        public readonly Site $site,
        public readonly Period $period,
        public readonly Filters $filters,
    ) {}

    public static function for(Site $site, Period $period, ?Filters $filters = null): self
    {
        return new self($site, $period, $filters ?? Filters::none());
    }

    public function previous(): self
    {
        return new self($this->site, $this->period->previous(), $this->filters);
    }

    public function withFilters(Filters $filters): self
    {
        return new self($this->site, $this->period, $filters);
    }

    // ---------------------------------------------------------------- Sources

    /**
     * The aggregated dates of the period (none when filters are applied).
     *
     * @return array<int, string>
     */
    public function rolledUpDates(): array
    {
        if ($this->rolledUpDates !== null) {
            return $this->rolledUpDates;
        }

        if (! $this->filters->isEmpty()) {
            return $this->rolledUpDates = [];
        }

        return $this->rolledUpDates = DailyStat::query()
            ->where('site_id', $this->site->getKey())
            ->where('dimension', '')
            ->whereBetween('date', [$this->period->fromDate(), $this->period->toDate()])
            ->pluck('date')
            ->map(static fn (mixed $date): string => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10))
            ->unique()
            ->values()
            ->all();
    }

    public function raw(): RawStats
    {
        return $this->raw ??= new RawStats($this->site, self::ranges(array_values(array_diff($this->period->dates(), $this->rolledUpDates()))), $this->filters);
    }

    /**
     * Whether some days of the period come from rollups.
     */
    public function usesRollups(): bool
    {
        return $this->rolledUpDates() !== [];
    }

    /**
     * Whether filtered numbers miss days before the raw retention window.
     */
    public function exceedsRawRetention(): bool
    {
        if ($this->filters->isEmpty()) {
            return false;
        }

        $days = (int) config('alfakit-analytics.raw_retention_days', 60);

        return $days > 0 && $this->period->fromDate() < CarbonImmutable::now($this->site->getTimezone())->subDays($days)->toDateString();
    }

    // ---------------------------------------------------------------- Numbers

    public function totals(): Metrics
    {
        return $this->raw()->totals()->add($this->rolledUp('')[''] ?? new Metrics);
    }

    /**
     * The chart's buckets (`Y-m-d` days, `H` hours or `Y-m` months), every bucket of the
     * period present.
     *
     * @return array<string, Metrics>
     */
    public function timeseries(): array
    {
        $interval = $this->period->interval();

        if ($interval === Period::HOUR) {
            $hours = $this->raw()->totalsByHour();
            $series = [];

            for ($hour = 0; $hour < 24; $hour++) {
                $series[str_pad((string) $hour, 2, '0', STR_PAD_LEFT)] = $hours[$hour] ?? new Metrics;
            }

            return $series;
        }

        $days = $this->raw()->totalsByDate();

        if ($this->usesRollups()) {
            foreach ($this->rolledUpQuery('')->selectRaw('date as bucket, '.self::sums())->groupBy('date')->get() as $row) {
                $date = substr((string) $row->getAttribute('bucket'), 0, 10);
                $days[$date] = ($days[$date] ?? new Metrics)->add(Metrics::fromRow($row->getAttributes()));
            }
        }

        $series = [];

        foreach ($this->period->dates() as $date) {
            $bucket = $interval === Period::MONTH ? substr($date, 0, 7) : $date;
            $series[$bucket] = ($series[$bucket] ?? new Metrics)->add($days[$date] ?? new Metrics);
        }

        return $series;
    }

    /**
     * The top values of a dimension.
     *
     * @return array<string, Metrics>
     */
    public function breakdown(string $dimension, ?int $limit = 10): array
    {
        if (! Dimension::isValid($dimension)) {
            return [];
        }

        $rows = $this->raw()->breakdown($dimension);

        foreach ($this->rolledUp($dimension) as $value => $metrics) {
            $rows[$value] = isset($rows[$value]) ? $rows[$value]->add($metrics) : $metrics;
        }

        // The site's goals, in their order, keep their row even without conversions.
        if ($dimension === Dimension::GOAL) {
            $ordered = [];

            foreach ($this->site->getGoals() as $goal) {
                $ordered[$goal->name] = $rows[$goal->name] ?? new Metrics;
            }

            return $limit === null ? $ordered : array_slice($ordered, 0, $limit, true);
        }

        uksort($rows, static function (string|int $a, string|int $b) use ($rows): int {
            return [$rows[$b]->visitors, $rows[$b]->pageviews, $rows[$b]->events, (string) $a] <=> [$rows[$a]->visitors, $rows[$a]->pageviews, $rows[$a]->events, (string) $b];
        });

        $rows = array_combine(array_map(strval(...), array_keys($rows)), array_values($rows));

        return $limit === null ? $rows : array_slice($rows, 0, $limit, true);
    }

    /**
     * Property keys of a custom event, with the most frequent first.
     *
     * @return array<int, string>
     */
    public function propertyKeys(string $event): array
    {
        $keys = $this->raw()->propertyKeys($event);

        if ($this->usesRollups()) {
            $prefix = Dimension::property($event, '');

            $rolled = DailyStat::query()
                ->where('site_id', $this->site->getKey())
                ->where('dimension', 'like', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%')
                ->whereIn('date', $this->rolledUpDates())
                ->distinct()
                ->pluck('dimension');

            foreach ($rolled as $dimension) {
                $property = Dimension::parseProperty((string) $dimension);

                if ($property !== null && $property[0] === $event) {
                    $keys[] = $property[1];
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Unique visitors seen in the last `realtime_window` minutes (filters apply).
     */
    public function currentVisitors(?CarbonImmutable $now = null): int
    {
        $minutes = max(1, (int) config('alfakit-analytics.realtime_window', 5));
        $since = ($now ?? CarbonImmutable::now())->subMinutes($minutes);

        $raw = new RawStats($this->site, [], $this->filters);
        $query = DB::table(RawStats::VISITS.' as v')->where('v.site_id', $this->site->getKey())->where('v.last_seen_at', '>=', $since);

        $raw->applyVisitFilters($query, 'v');
        $raw->applyEventFilters($query, 'v.id');

        return (int) $query->distinct()->count('v.visitor');
    }

    // ---------------------------------------------------------------- Rollups

    /**
     * Rolled-up metrics of a dimension over the aggregated dates, keyed by value.
     *
     * @return array<string, Metrics>
     */
    protected function rolledUp(string $dimension): array
    {
        if (! $this->usesRollups()) {
            return [];
        }

        $rows = [];

        foreach ($this->rolledUpQuery($dimension)->selectRaw('value, '.self::sums())->groupBy('value')->orderByRaw('SUM(visitors) DESC')->limit(RawStats::ROW_CAP)->get() as $row) {
            $rows[(string) $row->getAttribute('value')] = Metrics::fromRow($row->getAttributes());
        }

        return $rows;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<DailyStat>
     */
    protected function rolledUpQuery(string $dimension): \Illuminate\Database\Eloquent\Builder
    {
        $query = DailyStat::query()->where('site_id', $this->site->getKey())->where('dimension', $dimension);

        return $query->where(function (\Illuminate\Database\Eloquent\Builder $query): void {
            foreach (self::ranges($this->rolledUpDates()) as [$from, $to]) {
                $from === $to ? $query->orWhere('date', $from) : $query->orWhereBetween('date', [$from, $to]);
            }
        });
    }

    protected static function sums(): string
    {
        return 'SUM(visitors) as visitors, SUM(visits) as visits, SUM(pageviews) as pageviews, SUM(events) as events, SUM(bounces) as bounces, SUM(duration) as duration';
    }

    /**
     * Consecutive dates grouped into ranges: `[2026-01-01, 2026-01-02, 2026-01-05]` →
     * `[[2026-01-01, 2026-01-02], [2026-01-05, 2026-01-05]]`.
     *
     * @param  array<int, string>  $dates
     * @return array<int, array{0: string, 1: string}>
     */
    public static function ranges(array $dates): array
    {
        sort($dates);
        $ranges = [];

        foreach ($dates as $date) {
            $last = count($ranges) - 1;

            if ($last >= 0 && CarbonImmutable::parse($ranges[$last][1])->addDay()->toDateString() === $date) {
                $ranges[$last][1] = $date;
            } else {
                $ranges[] = [$date, $date];
            }
        }

        return $ranges;
    }
}
