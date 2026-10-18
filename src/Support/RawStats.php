<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Models\Event;
use AlfaSix\AlfakitAnalytics\Models\Site;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Metrics computed from the raw visits and events of some date ranges, with filters. The
 * aggregation command writes rollups with the same queries (one day, no filters), so
 * rolled-up and raw days always agree.
 *
 * Unique visitors are counted per day and then summed (the visitor hash changes daily).
 */
final class RawStats
{
    public const VISITS = 'alfakit_analytics_visits';

    public const EVENTS = 'alfakit_analytics_events';

    public const PROPERTIES = 'alfakit_analytics_event_properties';

    /** Most rows a breakdown reads before they are merged and cut to the requested limit. */
    public const ROW_CAP = 5000;

    /**
     * @param  array<int, array{0: string, 1: string}>  $ranges  Date ranges (`Y-m-d`, both ends included)
     */
    public function __construct(
        public readonly Site $site,
        public readonly array $ranges,
        public readonly Filters $filters,
    ) {}

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    // ---------------------------------------------------------------- Totals and series

    public function totals(): Metrics
    {
        if ($this->isEmpty()) {
            return new Metrics;
        }

        $metrics = Metrics::fromRow($this->sumByDate($this->visitsQuery(), self::visitMetrics(), 'v.date')->first() ?? []);

        // A page filter counts the page views of that page only.
        if ($this->filters->has(Dimension::PAGE)) {
            $metrics->pageviews = (int) $this->eventsQuery(Event::PAGEVIEW, filterPage: true)->count();
        }

        return $metrics;
    }

    /**
     * Totals per local date.
     *
     * @return array<string, Metrics>
     */
    public function totalsByDate(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $rows = $this->visitsQuery()
            ->selectRaw('v.date as bucket, '.self::visitMetrics())
            ->groupBy('v.date')
            ->get();

        $series = [];

        foreach ($rows as $row) {
            $series[substr((string) $row->bucket, 0, 10)] = Metrics::fromRow($row);
        }

        if ($this->filters->has(Dimension::PAGE)) {
            foreach ($this->eventsQuery(Event::PAGEVIEW, filterPage: true)->selectRaw('e.date as bucket, COUNT(*) as pageviews')->groupBy('e.date')->get() as $row) {
                $date = substr((string) $row->bucket, 0, 10);
                $series[$date] ??= new Metrics;
                $series[$date]->pageviews = (int) $row->pageviews;
            }
        }

        return $series;
    }

    /**
     * Visitors, visits and page views per local hour (0–23), from events.
     *
     * @return array<int, Metrics>
     */
    public function totalsByHour(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $rows = $this->eventsQuery(null, filterPage: true)
            ->selectRaw("e.hour as bucket, COUNT(DISTINCT e.visitor) as visitors, COUNT(DISTINCT e.visit_id) as visits, SUM(CASE WHEN e.name = 'pageview' THEN 1 ELSE 0 END) as pageviews, SUM(CASE WHEN e.name <> 'pageview' THEN 1 ELSE 0 END) as events")
            ->groupBy('e.hour')
            ->get();

        $series = [];

        foreach ($rows as $row) {
            $series[(int) $row->bucket] = Metrics::fromRow($row);
        }

        return $series;
    }

    // ---------------------------------------------------------------- Breakdowns

    /**
     * Metrics per value of a dimension, keyed by value (`''` for none), most visitors first.
     *
     * @return array<string, Metrics>
     */
    public function breakdown(string $dimension): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        if (Dimension::isVisitDimension($dimension)) {
            $value = "COALESCE(v.{$dimension}, '')";

            return $this->rows($this->sumByDate($this->visitsQuery(), self::visitMetrics(), 'v.date', $value));
        }

        if ($dimension === Dimension::PAGE) {
            return $this->rows($this->sumByDate(
                $this->eventsQuery(Event::PAGEVIEW, filterPage: true),
                'COUNT(DISTINCT e.visitor) as visitors, COUNT(DISTINCT e.visit_id) as visits, COUNT(*) as pageviews, 0 as events, 0 as bounces, 0 as duration',
                'e.date',
                'e.pathname',
            ));
        }

        if ($dimension === Dimension::EVENT) {
            return $this->rows($this->sumByDate(
                $this->eventsQuery(false),
                'COUNT(DISTINCT e.visitor) as visitors, COUNT(DISTINCT e.visit_id) as visits, 0 as pageviews, COUNT(*) as events, 0 as bounces, 0 as duration',
                'e.date',
                'e.name',
            ));
        }

        if ($dimension === Dimension::GOAL) {
            $rows = [];

            foreach ($this->site->getGoals() as $goal) {
                $metrics = $this->goal($goal);

                if ($metrics->visitors > 0 || $metrics->events > 0) {
                    $rows[$goal->name] = $metrics;
                }
            }

            return $rows;
        }

        if (($property = Dimension::parseProperty($dimension)) !== null) {
            [$event, $key] = $property;

            $query = $this->eventsQuery($event)
                ->join(self::PROPERTIES.' as p', 'p.event_id', '=', 'e.id')
                ->where('p.key', $key);

            return $this->rows($this->sumByDate(
                $query,
                'COUNT(DISTINCT e.visitor) as visitors, COUNT(DISTINCT e.visit_id) as visits, 0 as pageviews, COUNT(*) as events, 0 as bounces, 0 as duration',
                'e.date',
                'p.value',
            ));
        }

        return [];
    }

    /**
     * Visitors who reached a goal and how many times it was completed.
     */
    public function goal(Goal $goal): Metrics
    {
        if ($this->isEmpty()) {
            return new Metrics;
        }

        $query = $this->eventsQuery(null, filterPage: $goal->type === Goal::PAGE);
        $goal->constrain($query, 'e');

        return Metrics::fromRow($this->sumByDate(
            $query,
            'COUNT(DISTINCT e.visitor) as visitors, COUNT(DISTINCT e.visit_id) as visits, 0 as pageviews, COUNT(*) as events, 0 as bounces, 0 as duration',
            'e.date',
        )->first() ?? []);
    }

    /**
     * The property keys sent with a custom event.
     *
     * @return array<int, string>
     */
    public function propertyKeys(string $event): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        return $this->eventsQuery($event)
            ->join(self::PROPERTIES.' as p', 'p.event_id', '=', 'e.id')
            ->distinct()
            ->orderBy('p.key')
            ->limit(50)
            ->pluck('p.key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();
    }

    // ---------------------------------------------------------------- Queries

    /**
     * The visits of the ranges, filtered.
     */
    public function visitsQuery(): Builder
    {
        $query = DB::table(self::VISITS.' as v')->where('v.site_id', $this->site->getKey());

        $this->whereRanges($query, 'v.date');
        $this->applyVisitFilters($query, 'v');
        $this->applyEventFilters($query, 'v.id');

        return $query;
    }

    /**
     * The events of the ranges, filtered through their visit.
     *
     * @param  string|bool|null  $name  An event name, `false` for every custom event, null for every event
     * @param  bool  $filterPage  Whether a page filter restricts these events' own path (page views)
     */
    public function eventsQuery(string|bool|null $name, bool $filterPage = false): Builder
    {
        $query = DB::table(self::EVENTS.' as e')->where('e.site_id', $this->site->getKey());

        if (is_string($name)) {
            $query->where('e.name', $name);
        } elseif ($name === false) {
            $query->where('e.name', '<>', Event::PAGEVIEW);
        }

        $this->whereRanges($query, 'e.date');

        if ($this->filters->visitFilters() !== []) {
            $query->join(self::VISITS.' as v', 'v.id', '=', 'e.visit_id');
            $this->applyVisitFilters($query, 'v');
        }

        $eventFilters = $this->filters->eventFilters();

        if ($filterPage && isset($eventFilters[Dimension::PAGE])) {
            Sql::wherePattern($query, 'e.pathname', $eventFilters[Dimension::PAGE]);
            unset($eventFilters[Dimension::PAGE]);
        }

        $this->applyEventFilters($query, 'e.visit_id', $eventFilters);

        return $query;
    }

    protected function whereRanges(Builder $query, string $column): void
    {
        if ($this->ranges === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($column): void {
            foreach ($this->ranges as [$from, $to]) {
                $from === $to ? $query->orWhere($column, $from) : $query->orWhereBetween($column, [$from, $to]);
            }
        });
    }

    public function applyVisitFilters(Builder $query, string $alias): void
    {
        foreach ($this->filters->visitFilters() as $dimension => $value) {
            $column = "{$alias}.{$dimension}";

            if ($value === Dimension::NONE) {
                $query->where(static fn (Builder $query) => $query->whereNull($column)->orWhere($column, ''));
            } elseif (in_array($dimension, [Dimension::ENTRY_PAGE, Dimension::EXIT_PAGE], true)) {
                Sql::wherePattern($query, $column, $value);
            } else {
                $query->where($column, $value);
            }
        }
    }

    /**
     * Keep the visits (`$visitColumn`: the visit id column of the outer query) with an
     * event matching each event filter.
     *
     * @param  array<string, string>|null  $filters
     */
    public function applyEventFilters(Builder $query, string $visitColumn, ?array $filters = null): void
    {
        foreach ($filters ?? $this->filters->eventFilters() as $dimension => $value) {
            $goal = $dimension === Dimension::GOAL ? $this->site->getGoal($value) : null;

            if ($dimension === Dimension::GOAL && $goal === null) {
                $query->whereRaw('1 = 0');

                continue;
            }

            $query->whereExists(function (Builder $exists) use ($dimension, $value, $goal, $visitColumn): void {
                $exists->selectRaw('1')
                    ->from(self::EVENTS.' as f')
                    ->whereColumn('f.visit_id', $visitColumn);

                match ($dimension) {
                    Dimension::PAGE => Sql::wherePattern($exists->where('f.name', Event::PAGEVIEW), 'f.pathname', $value),
                    Dimension::EVENT => $exists->where('f.name', $value),
                    default => $goal?->constrain($exists, 'f'),
                };
            });
        }
    }

    /**
     * Metrics per value (or overall) per date, summed over the dates.
     */
    protected function sumByDate(Builder $query, string $metrics, string $dateColumn, ?string $value = null): Builder
    {
        $inner = $query->selectRaw(($value === null ? '' : "{$value} as value, ")."{$dateColumn} as bucket, {$metrics}")
            ->groupByRaw($value === null ? $dateColumn : "{$value}, {$dateColumn}");

        $outer = DB::query()->fromSub($inner, 't')
            ->selectRaw(($value === null ? '' : 'value, ').'SUM(visitors) as visitors, SUM(visits) as visits, SUM(pageviews) as pageviews, SUM(events) as events, SUM(bounces) as bounces, SUM(duration) as duration');

        if ($value !== null) {
            $outer->groupBy('value')->orderByDesc('visitors')->orderByDesc('pageviews')->orderByDesc('events')->limit(self::ROW_CAP);
        }

        return $outer;
    }

    /**
     * @return array<string, Metrics>
     */
    protected function rows(Builder $query): array
    {
        $rows = [];

        foreach ($query->get() as $row) {
            $rows[(string) $row->value] = Metrics::fromRow($row);
        }

        return $rows;
    }

    protected static function visitMetrics(): string
    {
        return 'COUNT(DISTINCT v.visitor) as visitors, COUNT(*) as visits, SUM(v.pageviews) as pageviews, SUM(v.events) as events, SUM(CASE WHEN v.is_bounce THEN 1 ELSE 0 END) as bounces, SUM(v.duration) as duration';
    }
}
