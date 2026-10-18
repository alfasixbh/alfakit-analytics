<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Widgets;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Models\Visit;
use AlfaSix\AlfakitAnalytics\Support\Format;
use AlfaSix\AlfakitAnalytics\Widgets\Concerns\InteractsWithAnalyticsReport;

/**
 * "Visitors now" and the period's KPIs with their change from the previous period and a
 * sparkline. Refreshes when visits are recorded on `realtime()` panels, else polls at the
 * plugin's `realtimePollingInterval()`.
 */
class AnalyticsOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithAnalyticsReport;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    public function __construct()
    {
        $interval = AnalyticsPlugin::get()->getRealtimePollingInterval();

        $this->configureLiveUpdates($interval !== null, 2000, $interval, [Visit::class]);
    }

    protected function getStats(): array
    {
        $report = $this->getReport();

        if ($report === null) {
            return [];
        }

        $now = $report->stats()->currentVisitors();
        $minutes = max(1, (int) config('alfakit-analytics.realtime_window', 5));

        $stats = [
            Stat::make(__('alfakit-analytics::analytics.metrics.current_visitors'), Format::number($now))
                ->description(trans_choice('alfakit-analytics::analytics.metrics.current_visitors_description', $minutes, ['minutes' => $minutes]))
                ->descriptionIcon('radio')
                ->color($now > 0 ? 'success' : null)
                ->extraAttributes(['data-analytics-stat' => 'current_visitors', 'aria-live' => 'polite']),
        ];

        foreach ($report->kpis() as $kpi) {
            if ($kpi['key'] === 'visits') {
                continue;
            }

            $stat = Stat::make($kpi['label'], $kpi['display'])
                ->extraAttributes(['data-analytics-stat' => $kpi['key']]);

            $nonZero = array_filter($kpi['series'], static fn (float|int $value): bool => (float) $value !== 0.0);

            if ($nonZero !== [] && count($kpi['series']) > 1) {
                $stat->chart($kpi['series'])->chartColor('primary');
            }

            if ($kpi['changeDisplay'] !== null) {
                $stat->description(__('alfakit-analytics::analytics.compare.change', ['change' => $kpi['changeDisplay']]))
                    ->descriptionIcon(($kpi['change'] ?? 0) >= 0 ? 'trending-up' : 'trending-down')
                    ->descriptionColor(match ($kpi['good']) {
                        true => 'success',
                        false => 'danger',
                        default => 'gray',
                    });
            }

            $stats[] = $stat;
        }

        return $stats;
    }
}
