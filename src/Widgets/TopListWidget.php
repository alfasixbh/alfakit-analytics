<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Widgets;

use AlfaSix\Alfakit\Widgets\Widget;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Support\Dimension;
use AlfaSix\AlfakitAnalytics\Support\Report;
use AlfaSix\AlfakitAnalytics\Widgets\Concerns\InteractsWithAnalyticsReport;

/**
 * A top-list card (`analytics:breakdown` in every renderer): tabs of dimensions (top,
 * entry and exit pages…), each a list of rows with a bar, visitors, a second metric and
 * the change from the previous period. Each row links to the dashboard filtered by it.
 *
 * `TopListWidget::make(['panel' => 'sources'])`; the panels are `Report::PANELS`.
 */
class TopListWidget extends Widget
{
    use InteractsWithAnalyticsReport;

    protected static bool $isDiscovered = false;

    protected static ?string $component = 'analytics:breakdown';

    public string $panel = 'pages';

    public function getType(): string
    {
        return 'AnalyticsBreakdown';
    }

    protected function getHeading(): string
    {
        return (string) __('alfakit-analytics::analytics.panels.'.$this->panel);
    }

    protected function getSchemaProps(): array
    {
        $report = $this->getReport();

        if ($report === null) {
            return ['heading' => $this->getHeading(), 'panel' => $this->panel, 'tabs' => []];
        }

        $limit = AnalyticsPlugin::get()->getTopListLimit();
        $tabs = [];
        $active = null;

        foreach ($report->panelDimensions($this->panel) as $dimension) {
            $secondary = Report::secondaryMetric($dimension);

            $tabs[] = [
                'key' => $dimension,
                'label' => Dimension::isProperty($dimension)
                    ? (string) __('alfakit-analytics::analytics.dimensions.property', ['key' => Dimension::label($dimension)])
                    : Dimension::label($dimension),
                'columns' => [Dimension::label($dimension), Report::metricLabel('visitors'), Report::metricLabel($secondary)],
                'rows' => $report->breakdown($dimension, $limit),
                'filtered' => ! Dimension::isProperty($dimension) && $report->filters->has($dimension),
            ];

            // Property tabs open first once an event is picked.
            if (Dimension::isProperty($dimension) && $active === null) {
                $active = $dimension;
            }
        }

        return [
            'heading' => $this->getHeading(),
            'panel' => $this->panel,
            'tabs' => $tabs,
            'activeTab' => $active,
            'compare' => $report->compare ?: null,
            'emptyLabel' => (string) __('alfakit-analytics::analytics.panels.empty'),
        ];
    }
}
