<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Models\DailyStat;
use AlfaSix\AlfakitAnalytics\Models\Event;
use AlfaSix\AlfakitAnalytics\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writes the daily rollups of a site: for one local date, the totals and every value of
 * every dimension, from the raw visits and events. Re-running replaces the day's rows, so
 * it is safe to aggregate a day again (after changing goals, say) while its raw data is
 * kept. Only finished days are aggregated: today always reads raw data.
 */
class Aggregator
{
    /**
     * Aggregate `$date` (`Y-m-d`, in the site's timezone). Returns the number of rows written.
     */
    public function aggregate(Site $site, string $date): int
    {
        $today = CarbonImmutable::now($site->getTimezone())->toDateString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("[{$date}] is not a Y-m-d date.");
        }

        if ($date >= $today) {
            return 0;
        }

        $raw = new RawStats($site, [[$date, $date]], Filters::none());
        $rows = [$this->row($site, $date, '', '', $raw->totals())];

        foreach (Dimension::rolledUp() as $dimension) {
            foreach ($raw->breakdown($dimension) as $value => $metrics) {
                $rows[] = $this->row($site, $date, $dimension, (string) $value, $metrics);
            }
        }

        $events = DB::table(RawStats::EVENTS)
            ->where('site_id', $site->getKey())
            ->where('date', $date)
            ->where('name', '<>', Event::PAGEVIEW)
            ->distinct()
            ->pluck('name');

        foreach ($events as $event) {
            foreach ($raw->propertyKeys((string) $event) as $key) {
                $dimension = Dimension::property((string) $event, $key);

                if (strlen($dimension) > 160) {
                    continue;
                }

                foreach ($raw->breakdown($dimension) as $value => $metrics) {
                    $rows[] = $this->row($site, $date, $dimension, (string) $value, $metrics);
                }
            }
        }

        DB::transaction(static function () use ($site, $date, $rows): void {
            DailyStat::query()->where('site_id', $site->getKey())->where('date', $date)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DailyStat::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Aggregate the finished days of a range (both ends included, in the site's timezone).
     */
    public function aggregateRange(Site $site, string $from, string $to): int
    {
        $written = 0;

        foreach (Period::custom($from, $to, $site->getTimezone())?->dates() ?? [] as $date) {
            $written += $this->aggregate($site, $date);
        }

        return $written;
    }

    /**
     * Aggregate the last `$days` finished days of every active site.
     *
     * @return array<int, int> Rows written per site id
     */
    public function aggregateRecent(int $days = 3): array
    {
        $written = [];

        foreach (Site::query()->orderBy('id')->cursor() as $site) {
            $yesterday = CarbonImmutable::now($site->getTimezone())->subDay();
            $written[$site->id] = $this->aggregateRange($site, $yesterday->subDays(max(1, $days) - 1)->toDateString(), $yesterday->toDateString());
        }

        return $written;
    }

    /**
     * @return array<string, int|string>
     */
    protected function row(Site $site, string $date, string $dimension, string $value, Metrics $metrics): array
    {
        return [
            'site_id' => $site->getKey(),
            'date' => $date,
            'dimension' => $dimension,
            'value' => mb_substr($value, 0, 512),
            ...$metrics->toArray(),
        ];
    }
}
