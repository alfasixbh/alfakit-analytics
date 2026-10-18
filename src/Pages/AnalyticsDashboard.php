<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\EmptyState;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\IconPosition;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Widgets\Widget;
use AlfaSix\AlfakitAnalytics\Actions\ExportTopListAction;
use AlfaSix\AlfakitAnalytics\Analytics;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Support\Dimension;
use AlfaSix\AlfakitAnalytics\Support\PageQuery;
use AlfaSix\AlfakitAnalytics\Support\Period;
use AlfaSix\AlfakitAnalytics\Support\Report;
use AlfaSix\AlfakitAnalytics\Widgets\AnalyticsOverviewWidget;
use AlfaSix\AlfakitAnalytics\Widgets\TopListWidget;
use AlfaSix\AlfakitAnalytics\Widgets\VisitorsChartWidget;

/**
 * The analytics dashboard of one site: KPIs with "visitors now", the visitors chart and
 * the top lists. Everything lives in the URL (`?site=1&period=7d&compare=1&filter[source]=Google`),
 * so every view can be bookmarked; clicking a row of a top list filters the dashboard.
 *
 * Extend it and pass your class to `AnalyticsPlugin::make()->usingPage(...)` to change it.
 */
class AnalyticsDashboard extends Page
{
    protected static ?string $slug = 'analytics';

    protected static bool $isDiscovered = false;

    protected ?Site $cachedSite = null;

    protected bool $hasResolvedSite = false;

    protected ?Report $cachedReport = null;

    public static function getPlugin(): AnalyticsPlugin
    {
        return AnalyticsPlugin::get();
    }

    public static function canAccess(): bool
    {
        return static::getPlugin()->can('viewAny');
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->cachedSite = null;
        $this->hasResolvedSite = false;
        $this->cachedReport = null;
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-analytics::analytics.page.title');
    }

    public function getSubheading(): ?string
    {
        $report = $this->getReport();

        if ($report === null) {
            return null;
        }

        $parts = [$report->site->domain ?? $report->site->name, $report->currentPeriodLabel()];

        if ($report->stats()->exceedsRawRetention()) {
            $parts[] = __('alfakit-analytics::analytics.page.filters_retention', ['days' => (int) config('alfakit-analytics.raw_retention_days', 60)]);
        }

        return implode(' · ', $parts);
    }

    public static function getNavigationLabel(): string
    {
        return static::getPlugin()->getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationIcon(): ?string
    {
        return static::getPlugin()->getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::getPlugin()->shouldRegisterNavigation() && static::canAccess();
    }

    // ---------------------------------------------------------------- Site and report

    /**
     * The sites the user may view, in name order.
     *
     * @return array<int, Site>
     */
    public function getSites(): array
    {
        $plugin = static::getPlugin();

        return $plugin->getSitesQuery()->orderBy('name')->orderBy('id')->limit(200)->get()
            ->filter(static fn (Site $site): bool => $plugin->can('view', $site))
            ->values()
            ->all();
    }

    /**
     * The site of `?site=` (when the user may view it), else the first one.
     */
    public function getSite(): ?Site
    {
        if ($this->hasResolvedSite) {
            return $this->cachedSite;
        }

        $this->hasResolvedSite = true;
        $sites = $this->getSites();
        $requested = PageQuery::get()['site'] ?? null;

        foreach ($sites as $site) {
            if (is_scalar($requested) && (string) $site->getKey() === (string) $requested) {
                return $this->cachedSite = $site;
            }
        }

        return $this->cachedSite = $sites[0] ?? null;
    }

    public function getReport(): ?Report
    {
        if ($this->cachedReport !== null) {
            return $this->cachedReport;
        }

        $site = $this->getSite();

        if ($site === null) {
            return null;
        }

        return $this->cachedReport = Report::fromQuery(
            $site,
            PageQuery::get(),
            fn (array $query): string => static::getUrl(['site' => $site->getKey(), ...$query], panel: $this->getPanel()),
        );
    }

    // ---------------------------------------------------------------- Content

    /**
     * @return array<Widget>
     */
    public function getWidgets(): array
    {
        if ($this->getReport() === null) {
            return [];
        }

        return [
            AnalyticsOverviewWidget::make(),
            VisitorsChartWidget::make(),
            ...array_map(static fn (string $panel): TopListWidget => TopListWidget::make(['panel' => $panel]), array_keys(Report::PANELS)),
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    public function content(Schema $schema): Schema
    {
        if ($this->getSite() !== null) {
            return $schema;
        }

        $resource = static::getPlugin()->getSiteResource();
        $canCreate = static::getPlugin()->hasSiteResource() && $resource::canCreate();

        return $schema->components([
            EmptyState::make(__('alfakit-analytics::analytics.page.empty.heading'))
                ->description(__('alfakit-analytics::analytics.page.empty.description'))
                ->icon('chart-line')
                ->footer($canCreate ? [
                    Action::make('createSite')
                        ->label(__('alfakit-analytics::analytics.page.empty.action'))
                        ->icon('plus')
                        ->button()
                        ->url($resource::getUrl('create')),
                ] : []),
        ]);
    }

    protected function getContentBlocks(): array
    {
        $blocks = parent::getContentBlocks();
        $widgets = $this->serializeWidgets($this->getWidgets(), $this->getColumns(), 'widgets');

        return $widgets === null ? $blocks : [...$blocks, $widgets];
    }

    // ---------------------------------------------------------------- Header actions

    protected function getHeaderActions(): array
    {
        $report = $this->getReport();

        if ($report === null) {
            return [];
        }

        $actions = [];

        foreach ($report->filterLabels() as $dimension => $label) {
            $actions[] = Action::make('removeFilter'.str_replace(['_', ':'], '', ucwords($dimension, '_:')))
                ->label($label)
                ->tooltip(__('alfakit-analytics::analytics.filters.remove'))
                ->icon('x')
                ->iconPosition(IconPosition::After)
                ->color('gray')
                ->outlined()
                ->size(Size::Small)
                ->url($report->urlWithoutFilter($dimension));
        }

        $sites = $this->getSites();

        if (count($sites) > 1) {
            $actions[] = ActionGroup::make(array_map(fn (Site $site): Action => Action::make('site'.$site->getKey())
                ->label($site->name)
                ->icon($site->is($report->site) ? 'check' : 'globe')
                ->url(static::getUrl(['site' => $site->getKey(), ...$report->query(['filter' => null])], panel: $this->getPanel())), $sites))
                ->label($report->site->name)
                ->icon('globe')
                ->button()
                ->color('gray');
        }

        $periods = array_map(static fn (string $preset): Action => Action::make('period'.str_replace('_', '', ucwords($preset, '_')))
            ->label(Report::periodLabel($preset))
            ->icon($report->period->preset === $preset ? 'check' : null)
            ->url($report->urlForPreset($preset)), Period::PRESETS);

        $periods[] = Action::make('customPeriod')
            ->label(__('alfakit-analytics::analytics.periods.custom'))
            ->icon($report->period->preset === Period::CUSTOM ? 'check' : 'calendar-range')
            ->modalHeading(__('alfakit-analytics::analytics.periods.custom'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('alfakit-analytics::analytics.periods.apply'))
            ->fillForm(['from' => $report->period->fromDate(), 'to' => $report->period->toDate()])
            ->schema([
                DatePicker::make('from')
                    ->label(__('alfakit-analytics::analytics.periods.from'))
                    ->required()
                    ->maxDate(now($report->site->getTimezone())->toDateString()),
                DatePicker::make('to')
                    ->label(__('alfakit-analytics::analytics.periods.to'))
                    ->required()
                    ->afterOrEqual('from')
                    ->maxDate(now($report->site->getTimezone())->toDateString()),
            ])
            ->action(function (array $data): mixed {
                $report = $this->getReport();
                $period = Period::custom(substr((string) ($data['from'] ?? ''), 0, 10), substr((string) ($data['to'] ?? ''), 0, 10), $report?->site->getTimezone() ?? 'UTC');

                return $report !== null && $period !== null ? redirect()->to($report->urlForRange($period->fromDate(), $period->toDate())) : null;
            });

        $actions[] = ActionGroup::make($periods)
            ->label($report->currentPeriodLabel())
            ->icon('calendar')
            ->button()
            ->color('gray');

        $actions[] = Action::make('compare')
            ->label($report->compare ? __('alfakit-analytics::analytics.compare.disable') : __('alfakit-analytics::analytics.compare.enable'))
            ->icon('arrow-left-right')
            ->color($report->compare ? 'primary' : 'gray')
            ->outlined(! $report->compare)
            ->button()
            ->url($report->urlToggleCompare());

        $more = [ExportTopListAction::make()];

        if (static::getPlugin()->canShare()) {
            $more[] = Action::make('share')
                ->label(__('alfakit-analytics::analytics.share.label'))
                ->icon('share-2')
                ->visible(fn (): bool => ($site = $this->getSite()) !== null && $site->is_shared && static::getPlugin()->can('update', $site))
                ->modalHeading(__('alfakit-analytics::analytics.share.heading'))
                ->modalDescription(__('alfakit-analytics::analytics.share.description'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('alfakit-analytics::analytics.share.close'))
                ->fillForm(fn (): array => ['link' => ($site = $this->getSite()) !== null ? Analytics::shareUrl($site) : null])
                ->schema([
                    TextInput::make('link')
                        ->label(__('alfakit-analytics::analytics.share.link'))
                        ->readOnly()
                        ->copyable(copyMessage: __('alfakit-analytics::analytics.share.copied')),
                ]);
        }

        $actions[] = ActionGroup::make($more)
            ->label(__('alfakit-analytics::analytics.actions.more'))
            ->icon('ellipsis-vertical')
            ->iconButton()
            ->color('gray');

        return $actions;
    }

    /**
     * The dimensions the export can pick from.
     *
     * @return array<string, string>
     */
    public function getExportableDimensions(): array
    {
        $report = $this->getReport();
        $options = [];

        foreach (array_keys(Report::PANELS) as $panel) {
            foreach ($report?->panelDimensions($panel) ?? Report::PANELS[$panel] as $dimension) {
                $options[$dimension] = Dimension::label($dimension);
            }
        }

        return $options;
    }
}
