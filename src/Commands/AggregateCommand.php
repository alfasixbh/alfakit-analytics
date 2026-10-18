<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Commands;

use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Support\Aggregator;
use AlfaSix\AlfakitAnalytics\Support\Pruner;
use Illuminate\Console\Command;

/**
 * Writes the daily rollups of the last finished days, then applies the retention
 * settings and rotates the visitor salt. Scheduled hourly by the plugin.
 */
class AggregateCommand extends Command
{
    protected $signature = 'alfakit-analytics:aggregate
        {--days=3 : Re-aggregate this many finished days}
        {--from= : Aggregate from this date (Y-m-d) instead}
        {--to= : Aggregate up to this date (Y-m-d, default yesterday)}
        {--site= : Only the site with this key}
        {--no-prune : Skip the retention clean-up}';

    protected $description = 'Aggregate AlfaKit Analytics rollups and prune data past its retention';

    public function handle(Aggregator $aggregator, Pruner $pruner): int
    {
        $sites = Site::query()->orderBy('id');

        if (is_string($key = $this->option('site')) && $key !== '') {
            $sites->where('key', $key);
        }

        $from = $this->option('from');
        $to = $this->option('to');
        $days = max(1, (int) $this->option('days'));
        $rows = 0;

        foreach ($sites->cursor() as $site) {
            $yesterday = now($site->getTimezone())->subDay()->toDateString();
            $start = is_string($from) && $from !== '' ? $from : now($site->getTimezone())->subDays($days)->toDateString();
            $end = is_string($to) && $to !== '' ? $to : $yesterday;

            $rows += $aggregator->aggregateRange($site, $start, min($end, $yesterday));
        }

        $this->components->info("Wrote {$rows} rollup rows.");

        if (! $this->option('no-prune')) {
            $pruned = $pruner->prune();
            $this->components->info("Pruned {$pruned['raw']} visits, {$pruned['rollups']} rollup rows and {$pruned['reports']} export rows.");
        }

        return self::SUCCESS;
    }
}
