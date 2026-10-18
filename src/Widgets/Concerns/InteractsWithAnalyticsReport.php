<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Widgets\Concerns;

use AlfaSix\AlfakitAnalytics\Pages\AnalyticsDashboard;
use AlfaSix\AlfakitAnalytics\Support\Report;

/**
 * The report of the analytics dashboard the widget is on.
 *
 * @mixin \AlfaSix\Alfakit\Widgets\Widget
 */
trait InteractsWithAnalyticsReport
{
    public function getReport(): ?Report
    {
        $page = $this->getPage();

        return $page instanceof AnalyticsDashboard ? $page->getReport() : null;
    }
}
