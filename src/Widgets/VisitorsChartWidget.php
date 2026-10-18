<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Widgets;

use AlfaSix\Alfakit\Widgets\ChartWidget;
use AlfaSix\AlfakitAnalytics\Support\Format;
use AlfaSix\AlfakitAnalytics\Support\Report;
use AlfaSix\AlfakitAnalytics\Widgets\Concerns\InteractsWithAnalyticsReport;
use Illuminate\Support\HtmlString;

/**
 * The chosen metric over the period (hours of a single day, months of long ranges), with
 * the previous period as a dashed line when comparing.
 */
class VisitorsChartWidget extends ChartWidget
{
    use InteractsWithAnalyticsReport;

    protected static bool $isDiscovered = false;

    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected ?string $color = 'primary';

    protected function getChartType(): string
    {
        return 'line';
    }

    protected function getHeading(): ?string
    {
        return (string) __('alfakit-analytics::analytics.chart.heading');
    }

    /**
     * @return array<string, string>|null
     */
    protected function getFilters(): ?array
    {
        $metrics = $this->getReport()?->chartMetrics() ?? ['visitors'];

        return array_combine($metrics, array_map(Report::metricLabel(...), $metrics));
    }

    protected function getData(): array
    {
        $report = $this->getReport();

        if ($report === null) {
            return [];
        }

        $metric = $this->getFilter() ?? 'visitors';
        $chart = $report->chart($metric);

        $datasets = [[
            'label' => Report::metricLabel($metric),
            'data' => $chart['current'],
            'fill' => true,
            'tension' => 0.3,
            'pointRadius' => count($chart['current']) > 40 ? 0 : 3,
        ]];

        if ($chart['previous'] !== null) {
            $datasets[] = [
                'label' => __('alfakit-analytics::analytics.chart.previous', ['metric' => Report::metricLabel($metric)]),
                'data' => $chart['previous'],
                'fill' => false,
                'tension' => 0.3,
                'borderDash' => [6, 4],
                'pointRadius' => 0,
                'borderColor' => 'rgba(148, 163, 184, 0.9)',
                'backgroundColor' => 'rgba(148, 163, 184, 0.15)',
            ];
        }

        return ['labels' => $chart['labels'], 'datasets' => $datasets];
    }

    protected function getOptions(): ?array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => ['y' => ['beginAtZero' => true]],
            'plugins' => ['legend' => ['display' => $this->getReport()?->compare === true]],
        ];
    }

    public function isEmpty(): bool
    {
        return $this->getReport() === null;
    }

    /**
     * A table of the values for screen readers.
     */
    protected function getChartAssistiveContent(): HtmlString
    {
        $report = $this->getReport();

        if ($report === null) {
            return new HtmlString('');
        }

        $metric = $this->getFilter() ?? 'visitors';
        $chart = $report->chart($metric);
        $rows = '';

        foreach ($chart['labels'] as $index => $label) {
            $rows .= '<tr><th scope="row">'.e($label).'</th><td>'.e(Format::metric($metric, $chart['current'][$index] ?? null)).'</td></tr>';
        }

        return new HtmlString('<table><caption>'.e(Report::metricLabel($metric)).'</caption><tbody>'.$rows.'</tbody></table>');
    }
}
