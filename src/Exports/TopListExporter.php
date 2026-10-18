<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Exports;

use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\AlfakitAnalytics\Models\ReportRow;
use AlfaSix\AlfakitAnalytics\Support\Dimension;
use AlfaSix\AlfakitAnalytics\Support\Report;
use Illuminate\Support\Str;

/**
 * The columns of an exported top list (snapshots in `ReportRow`).
 */
class TopListExporter extends Exporter
{
    protected static ?string $model = ReportRow::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('label')->label(__('alfakit-analytics::analytics.export.columns.value')),
            ExportColumn::make('visitors')->label(Report::metricLabel('visitors')),
            ExportColumn::make('visits')->label(Report::metricLabel('visits')),
            ExportColumn::make('pageviews')->label(Report::metricLabel('pageviews')),
            ExportColumn::make('events')->label(Report::metricLabel('events')),
            ExportColumn::make('bounce_rate')->label(Report::metricLabel('bounce_rate')),
            ExportColumn::make('visit_duration')->label(__('alfakit-analytics::analytics.export.columns.visit_duration_seconds')),
            ExportColumn::make('conversion_rate')->label(Report::metricLabel('conversion_rate')),
            ExportColumn::make('change')->label(__('alfakit-analytics::analytics.export.columns.change')),
        ];
    }

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-analytics::analytics.export.model');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-analytics::analytics.export.plural_model');
    }

    public function getFileName(Export $export): string
    {
        $dimension = $this->options['dimension'] ?? 'top-list';

        return 'analytics-'.Str::slug(is_string($dimension) && Dimension::isValid($dimension) ? str_replace(':', '-', $dimension) : 'top-list').'-'.$export->getKey();
    }
}
