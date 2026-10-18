<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Models\DailyStat;
use AlfaSix\AlfakitAnalytics\Models\ReportRow;
use AlfaSix\AlfakitAnalytics\Models\Site;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Applies the retention settings: raw visits and events older than
 * `raw_retention_days` (aggregated first, so no day is lost), rollups older than
 * `rollup_retention_days`, export snapshots older than a day and expired salts.
 */
class Pruner
{
    public function __construct(protected Aggregator $aggregator) {}

    /**
     * @return array{raw: int, rollups: int, reports: int}
     */
    public function prune(): array
    {
        Salts::rotateIfDue();

        $raw = 0;
        $rollups = 0;
        $rawDays = (int) config('alfakit-analytics.raw_retention_days', 60);
        $rollupDays = config('alfakit-analytics.rollup_retention_days');

        foreach (Site::query()->orderBy('id')->cursor() as $site) {
            $today = CarbonImmutable::now($site->getTimezone());

            if ($rawDays > 0) {
                $raw += $this->pruneRaw($site, $today->subDays($rawDays)->toDateString());
            }

            if (is_numeric($rollupDays) && (int) $rollupDays > 0) {
                $rollups += DailyStat::query()->where('site_id', $site->getKey())->where('date', '<', $today->subDays((int) $rollupDays)->toDateString())->delete();
            }
        }

        $reports = ReportRow::query()->where('created_at', '<', CarbonImmutable::now()->subDay())->delete();

        return ['raw' => $raw, 'rollups' => $rollups, 'reports' => $reports];
    }

    /**
     * Delete the raw data of the days before `$before`, aggregating days without rollups first.
     */
    public function pruneRaw(Site $site, string $before): int
    {
        $dates = DB::table(RawStats::VISITS)->where('site_id', $site->getKey())->where('date', '<', $before)->distinct()->pluck('date')
            ->map(static fn (mixed $date): string => substr((string) $date, 0, 10));

        $aggregated = DailyStat::query()->where('site_id', $site->getKey())->where('dimension', '')->where('date', '<', $before)->pluck('date')
            ->map(static fn (mixed $date): string => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10))
            ->all();

        foreach ($dates->diff($aggregated) as $date) {
            $this->aggregator->aggregate($site, $date);
        }

        $deleted = 0;

        do {
            $ids = DB::table(RawStats::VISITS)->where('site_id', $site->getKey())->where('date', '<', $before)->orderBy('id')->limit(1000)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(static function () use ($ids): void {
                $events = DB::table(RawStats::EVENTS)->whereIn('visit_id', $ids)->pluck('id')->all();

                foreach (array_chunk($events, 1000) as $chunk) {
                    DB::table(RawStats::PROPERTIES)->whereIn('event_id', $chunk)->delete();
                    DB::table(RawStats::EVENTS)->whereIn('id', $chunk)->delete();
                }

                DB::table(RawStats::VISITS)->whereIn('id', $ids)->delete();
            });

            $deleted += count($ids);
        } while (true);

        return $deleted;
    }
}
