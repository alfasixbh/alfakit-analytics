<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Actions;

use AlfaSix\Alfakit\Actions\ExportAction;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Exports\TopListExporter;
use AlfaSix\AlfakitAnalytics\Models\ReportRow;
use AlfaSix\AlfakitAnalytics\Pages\AnalyticsDashboard;
use AlfaSix\AlfakitAnalytics\Support\Dimension;
use AlfaSix\AlfakitAnalytics\Support\Report;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Export a top list of the dashboard (with its period and filters) through AlfaKit's
 * exporter: CSV or XLSX on the queue, downloaded from the notification. The rows are
 * snapshotted first, since the exporter exports records by key.
 */
class ExportTopListAction extends ExportAction
{
    /** Most rows of one export. */
    public const MAX_ROWS = 1000;

    public static function getDefaultName(): string
    {
        return 'exportTopList';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->exporter(TopListExporter::class);
        $this->columnMapping(false);
        $this->label(__('alfakit-analytics::analytics.export.label'));
        $this->modalHeading(__('alfakit-analytics::analytics.export.heading'));
        $this->modalDescription(__('alfakit-analytics::analytics.export.description'));
        $this->requiresConfirmation(false);

        $this->authorize(function (): bool {
            $page = $this->getDashboard();
            $site = $page?->getSite();

            return $site !== null && AnalyticsPlugin::get()->can('view', $site);
        });

        $this->action(function (array $data, ExportTopListAction $action): void {
            $action->startExport($action->snapshot((string) ($data['dimension'] ?? '')), $data);
        });
    }

    protected function getDashboard(): ?AnalyticsDashboard
    {
        $context = $this->getContext();

        return $context instanceof AnalyticsDashboard ? $context : null;
    }

    protected function getExportFormComponents(): array
    {
        $options = $this->getDashboard()?->getExportableDimensions() ?? [];

        return [
            Select::make('dimension')
                ->label(__('alfakit-analytics::analytics.export.dimension'))
                ->options($options)
                ->default(array_key_first($options))
                ->required()
                ->in(array_keys($options)),
            ...parent::getExportFormComponents(),
        ];
    }

    /**
     * Store the rows of the top list and return their keys, in order.
     *
     * @return array<int, int>
     */
    public function snapshot(string $dimension): array
    {
        $report = $this->getDashboard()?->getReport();

        if ($report === null || ! Dimension::isValid($dimension)) {
            return [];
        }

        $uuid = (string) Str::uuid();
        $secondary = Report::secondaryMetric($dimension);
        $metrics = $report->breakdownMetrics($dimension, self::MAX_ROWS);
        $previous = $report->compare ? $report->previousStats()?->breakdown($dimension, null) : null;
        $total = $report->stats()->totals()->visitors;
        $now = Carbon::now();
        $position = 0;
        $rows = [];

        foreach ($metrics as $value => $row) {
            $value = (string) $value;
            $change = $previous === null ? null : \AlfaSix\AlfakitAnalytics\Support\Metrics::change($row->visitors, ($previous[$value] ?? new \AlfaSix\AlfakitAnalytics\Support\Metrics)->visitors);

            $rows[] = [
                'report' => $uuid,
                'site_id' => $report->site->getKey(),
                'position' => ++$position,
                'dimension' => mb_substr($dimension, 0, 160),
                'label' => mb_substr(Dimension::valueLabel($dimension, $value), 0, 512),
                'visitors' => $row->visitors,
                'visits' => $row->visits,
                'pageviews' => $row->pageviews,
                'events' => $row->events,
                'bounce_rate' => Dimension::isVisitDimension($dimension) ? $row->bounceRate() : null,
                'visit_duration' => Dimension::isVisitDimension($dimension) ? $row->visitDuration() : null,
                'conversion_rate' => $secondary === 'conversion_rate' && $total > 0 ? round($row->visitors / $total * 100, 2) : null,
                'change' => $change === null ? null : max(-999999, min(999999, $change)),
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ReportRow::query()->insert($chunk);
        }

        return ReportRow::query()->where('report', $uuid)->orderBy('position')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }
}
