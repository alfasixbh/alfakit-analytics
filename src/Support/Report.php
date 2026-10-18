<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Models\Site;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The dashboard of one site as the request asks for it (`?period=7d&compare=1&filter[source]=Google`):
 * KPIs, the chart and the top lists, with links that change the period or add and remove
 * filters. Used by the panel page and the shared dashboard.
 */
final class Report
{
    /** The metrics the chart can show. */
    public const CHART_METRICS = ['visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'visit_duration'];

    /** Metrics where a decrease is the good direction. */
    public const LOWER_IS_BETTER = ['bounce_rate'];

    /**
     * The top-list cards and their tabs.
     *
     * @var array<string, array<int, string>>
     */
    public const PANELS = [
        'pages' => [Dimension::PAGE, Dimension::ENTRY_PAGE, Dimension::EXIT_PAGE],
        'sources' => [Dimension::SOURCE, Dimension::CHANNEL, Dimension::REFERRER],
        'campaigns' => [Dimension::UTM_CAMPAIGN, Dimension::UTM_SOURCE, Dimension::UTM_MEDIUM, Dimension::UTM_CONTENT, Dimension::UTM_TERM],
        'locations' => [Dimension::COUNTRY],
        'devices' => [Dimension::DEVICE, Dimension::BROWSER, Dimension::OS],
        'events' => [Dimension::EVENT],
        'goals' => [Dimension::GOAL],
    ];

    private ?Stats $stats = null;

    private ?Stats $previousStats = null;

    /** @var array<string, array<string, Metrics>> */
    private array $breakdowns = [];

    /**
     * @param  Closure(array<string, mixed>): string  $urlUsing  Builds a dashboard URL from query parameters
     */
    public function __construct(
        public readonly Site $site,
        public readonly Period $period,
        public readonly Filters $filters,
        public readonly bool $compare,
        protected Closure $urlUsing,
    ) {}

    /**
     * @param  array<array-key, mixed>  $query  The request's query string
     * @param  Closure(array<string, mixed>): string  $urlUsing
     */
    public static function fromQuery(Site $site, array $query, Closure $urlUsing): self
    {
        return new self(
            $site,
            Period::fromInput($query['period'] ?? null, $query['from'] ?? null, $query['to'] ?? null, $site->getTimezone()),
            Filters::from($query['filter'] ?? []),
            in_array($query['compare'] ?? null, ['1', 1, true, 'true'], true),
            $urlUsing,
        );
    }

    public function stats(): Stats
    {
        return $this->stats ??= Stats::for($this->site, $this->period, $this->filters);
    }

    public function previousStats(): ?Stats
    {
        return $this->compare ? ($this->previousStats ??= $this->stats()->previous()) : null;
    }

    // ---------------------------------------------------------------- Links

    /**
     * The query parameters of this report, with `$changes` applied (null removes a key).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function query(array $changes = []): array
    {
        $query = [
            ...$this->period->toQuery(),
            'compare' => $this->compare ? '1' : null,
            'filter' => $this->filters->all() ?: null,
        ];

        foreach ($changes as $key => $value) {
            $query[$key] = $value;
        }

        if (($query['period'] ?? null) !== Period::CUSTOM) {
            unset($query['from'], $query['to']);
        }

        return array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function url(array $changes = []): string
    {
        return ($this->urlUsing)($this->query($changes));
    }

    public function urlWithFilter(string $dimension, string $value): string
    {
        return $this->url(['filter' => $this->filters->with($dimension, $value)->all()]);
    }

    public function urlWithoutFilter(string $dimension): string
    {
        return $this->url(['filter' => $this->filters->without($dimension)->all() ?: null]);
    }

    public function urlForPreset(string $preset): string
    {
        return $this->url(['period' => $preset, 'from' => null, 'to' => null]);
    }

    public function urlForRange(string $from, string $to): string
    {
        return $this->url(['period' => Period::CUSTOM, 'from' => $from, 'to' => $to]);
    }

    public function urlToggleCompare(): string
    {
        return $this->url(['compare' => $this->compare ? null : '1']);
    }

    public static function periodLabel(string $preset): string
    {
        return (string) __('alfakit-analytics::analytics.periods.'.$preset);
    }

    /**
     * `Last 30 days`, or the dates of a custom range.
     */
    public function currentPeriodLabel(): string
    {
        if ($this->period->preset !== Period::CUSTOM) {
            return self::periodLabel($this->period->preset);
        }

        return $this->period->isSingleDay()
            ? $this->period->from->locale(app()->getLocale())->isoFormat('ll')
            : __('alfakit-analytics::analytics.periods.range', [
                'from' => $this->period->from->locale(app()->getLocale())->isoFormat('ll'),
                'to' => $this->period->to->locale(app()->getLocale())->isoFormat('ll'),
            ]);
    }

    // ---------------------------------------------------------------- KPIs

    /**
     * @return array<int, array{key: string, label: string, value: float|int|null, display: string, change: float|null, changeDisplay: string|null, good: bool|null, series: array<int, float|int>}>
     */
    public function kpis(): array
    {
        $current = $this->stats()->totals();
        $previous = $this->previousStats()?->totals();
        $series = $this->stats()->timeseries();
        $kpis = [];

        foreach (self::CHART_METRICS as $metric) {
            $value = $current->value($metric);
            $change = $previous === null ? null : Metrics::change($value, $previous->value($metric));

            $kpis[] = [
                'key' => $metric,
                'label' => self::metricLabel($metric),
                'value' => $value,
                'display' => Format::metric($metric, $value),
                'change' => $change,
                'changeDisplay' => Format::change($change),
                'good' => $change === null || $change === 0.0 ? null : (in_array($metric, self::LOWER_IS_BETTER, true) ? $change < 0 : $change > 0),
                'series' => array_values(array_map(static fn (Metrics $metrics): float|int => $metrics->value($metric) ?? 0, $series)),
            ];
        }

        return $kpis;
    }

    public static function metricLabel(string $metric): string
    {
        return (string) __('alfakit-analytics::analytics.metrics.'.$metric);
    }

    // ---------------------------------------------------------------- Chart

    /**
     * @return array<int, string>
     */
    public function chartMetrics(): array
    {
        return $this->period->interval() === Period::HOUR ? ['visitors', 'visits', 'pageviews'] : self::CHART_METRICS;
    }

    /**
     * Labels and values of the chart, with the previous period's values when comparing.
     *
     * @return array{labels: array<int, string>, current: array<int, float|int|null>, previous: array<int, float|int|null>|null}
     */
    public function chart(string $metric): array
    {
        $metric = in_array($metric, $this->chartMetrics(), true) ? $metric : 'visitors';
        $current = $this->stats()->timeseries();
        $previous = $this->previousStats()?->timeseries();
        $labels = [];

        foreach (array_keys($current) as $bucket) {
            $labels[] = $this->bucketLabel((string) $bucket);
        }

        return [
            'labels' => $labels,
            'current' => array_values(array_map(static fn (Metrics $metrics): float|int|null => $metrics->value($metric), $current)),
            // Aligned by position: the n-th day of this period next to the n-th of the previous.
            'previous' => $previous === null ? null : array_slice(array_pad(array_values(array_map(static fn (Metrics $metrics): float|int|null => $metrics->value($metric), $previous)), count($current), null), 0, count($current)),
        ];
    }

    public function bucketLabel(string $bucket): string
    {
        $locale = app()->getLocale();

        return match ($this->period->interval()) {
            Period::HOUR => CarbonImmutable::createFromTime((int) $bucket)->locale($locale)->isoFormat('LT'),
            Period::MONTH => CarbonImmutable::createFromFormat('!Y-m', $bucket)?->locale($locale)->isoFormat('MMM YYYY') ?? $bucket,
            default => CarbonImmutable::createFromFormat('!Y-m-d', $bucket)?->locale($locale)->isoFormat('D MMM') ?? $bucket,
        };
    }

    // ---------------------------------------------------------------- Top lists

    /**
     * The tabs of a top-list card; the events card gets a tab per property of the event
     * it is filtered by.
     *
     * @return array<int, string>
     */
    public function panelDimensions(string $panel): array
    {
        $dimensions = self::PANELS[$panel] ?? [];

        if ($panel === 'events' && ($event = $this->filters->get(Dimension::EVENT)) !== null) {
            foreach ($this->stats()->propertyKeys($event) as $key) {
                $dimensions[] = Dimension::property($event, $key);
            }
        }

        return $dimensions;
    }

    /**
     * The secondary metric shown next to visitors in a top list.
     */
    public static function secondaryMetric(string $dimension): string
    {
        return match (true) {
            $dimension === Dimension::PAGE => 'pageviews',
            $dimension === Dimension::ENTRY_PAGE, in_array($dimension, [Dimension::SOURCE, Dimension::CHANNEL, Dimension::REFERRER], true) => 'bounce_rate',
            $dimension === Dimension::GOAL => 'conversion_rate',
            $dimension === Dimension::EVENT, Dimension::isProperty($dimension) => 'events',
            default => 'visits',
        };
    }

    /**
     * @return array<string, Metrics>
     */
    public function breakdownMetrics(string $dimension, ?int $limit = 10): array
    {
        return $this->breakdowns[$dimension.'|'.($limit ?? 'all')] ??= $this->stats()->breakdown($dimension, $limit);
    }

    /**
     * The rows of a top list: label, raw filter value, metrics, bar width, change and the
     * link that filters the dashboard by the row (or removes that filter).
     *
     * @return array<int, array{key: string, value: string, label: string, flag: string|null, visitors: int, secondary: float|int|null, display: array<int, string>, bar: float, change: float|null, changeDisplay: string|null, url: string, active: bool}>
     */
    public function breakdown(string $dimension, ?int $limit = 10): array
    {
        $rows = $this->breakdownMetrics($dimension, $limit);
        $previous = $this->compare ? $this->previousStats()?->breakdown($dimension, null) : null;
        $secondary = self::secondaryMetric($dimension);
        $totalVisitors = $secondary === 'conversion_rate' ? $this->stats()->totals()->visitors : 0;
        $max = max(1, ...array_map(static fn (Metrics $metrics): int => $metrics->visitors, array_values($rows) ?: [new Metrics]));
        $filterDimension = Dimension::isProperty($dimension) ? null : $dimension;
        $result = [];

        foreach ($rows as $value => $metrics) {
            $value = (string) $value;
            $second = $secondary === 'conversion_rate'
                ? ($totalVisitors > 0 ? round($metrics->visitors / $totalVisitors * 100, 1) : null)
                : $metrics->value($secondary);
            $change = $previous === null ? null : Metrics::change($metrics->visitors, ($previous[$value] ?? new Metrics)->visitors);
            $filterValue = $value === '' ? Dimension::NONE : $value;
            $active = $filterDimension !== null && $this->filters->get($filterDimension) === $filterValue;

            $result[] = [
                'key' => $dimension.':'.$value,
                'value' => $value,
                'label' => Dimension::valueLabel($dimension, $value),
                'flag' => $dimension === Dimension::COUNTRY ? Dimension::countryFlag($value) : null,
                'visitors' => $metrics->visitors,
                'secondary' => $second,
                'display' => [Format::number($metrics->visitors), Format::metric($secondary, $second)],
                'bar' => round($metrics->visitors / $max, 4),
                'change' => $change,
                'changeDisplay' => Format::change($change),
                'url' => $filterDimension === null ? '' : ($active ? $this->urlWithoutFilter($filterDimension) : $this->urlWithFilter($filterDimension, $filterValue)),
                'active' => $active,
            ];
        }

        return $result;
    }

    /**
     * Human-readable descriptions of the active filters: `[dimension => "Source is Google"]`.
     *
     * @return array<string, string>
     */
    public function filterLabels(): array
    {
        $labels = [];

        foreach ($this->filters->all() as $dimension => $value) {
            $labels[$dimension] = (string) __('alfakit-analytics::analytics.filters.is', [
                'dimension' => Dimension::label($dimension),
                'value' => Dimension::valueLabel($dimension, $value),
            ]);
        }

        return $labels;
    }
}
