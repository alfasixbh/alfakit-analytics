<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasPoweredByCredit;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Pages\AnalyticsDashboard;
use AlfaSix\AlfakitAnalytics\Resources\SiteResource;
use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Privacy-first analytics of your public site, viewed in the panel: a dashboard page and
 * a resource to manage the tracked sites, their goals and shared links.
 *
 * ```php
 * $panel->plugin(
 *     AnalyticsPlugin::make()
 *         ->authorize(fn (User $user): bool => $user->is_admin)
 *         ->navigationGroup('Marketing')
 *         ->topListLimit(10),
 * );
 * ```
 *
 * On tenancy panels each tenant manages its own sites (`scopeToTenant(false)` shares
 * panel-wide sites with everyone). A `SitePolicy` registered for `Site` is honoured too
 * (`view` for the dashboard, `update` for sharing, the usual resource abilities).
 */
class AnalyticsPlugin implements Plugin
{
    use EvaluatesClosures;
    use HasPoweredByCredit;

    public const ID = 'analytics';

    /** @var class-string<AnalyticsDashboard> */
    protected string $page = AnalyticsDashboard::class;

    /** @var class-string<SiteResource> */
    protected string $resource = SiteResource::class;

    protected bool $hasSiteResource = true;

    protected bool|Closure|null $authorizeUsing = null;

    protected bool|Closure $isScopedToTenant = true;

    protected bool|Closure $canShare = true;

    protected int|Closure $topListLimit = 10;

    protected string|Closure|null $realtimePollingInterval = '15s';

    protected string|Closure|null $navigationLabel = null;

    protected string|Closure|null $navigationGroup = null;

    protected string|Closure|null $navigationIcon = 'chart-line';

    protected int|Closure|null $navigationSort = null;

    protected bool|Closure $shouldRegisterNavigation = true;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin registered on the current panel, or one with the default options.
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        if ($panel->hasPlugin(self::ID)) {
            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([$this->page]);

        if ($this->hasSiteResource) {
            $panel->resources([$this->resource]);
        }

        $panel->frontendTranslations(['analytics' => 'alfakit-analytics::analytics.ui']);
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Page and resource

    /**
     * @param  class-string<AnalyticsDashboard>  $page
     */
    public function usingPage(string $page): static
    {
        if (! is_a($page, AnalyticsDashboard::class, true)) {
            throw new InvalidArgumentException("The analytics page [{$page}] must extend [".AnalyticsDashboard::class.'].');
        }

        $this->page = $page;

        return $this;
    }

    /**
     * @return class-string<AnalyticsDashboard>
     */
    public function getPage(): string
    {
        return $this->page;
    }

    /**
     * @param  class-string<SiteResource>  $resource
     */
    public function usingSiteResource(string $resource): static
    {
        if (! is_a($resource, SiteResource::class, true)) {
            throw new InvalidArgumentException("The site resource [{$resource}] must extend [".SiteResource::class.'].');
        }

        $this->resource = $resource;

        return $this;
    }

    /**
     * @return class-string<SiteResource>
     */
    public function getSiteResource(): string
    {
        return $this->resource;
    }

    /**
     * Register the sites resource (default). Turn it off to manage sites in code.
     */
    public function siteResource(bool $condition = true): static
    {
        $this->hasSiteResource = $condition;

        return $this;
    }

    public function hasSiteResource(): bool
    {
        return $this->hasSiteResource;
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Who may open the dashboard and manage sites: `fn (User $user): bool => $user->is_admin`.
     * Without it every user of the panel may (subject to a `Site` policy).
     */
    public function authorize(bool|Closure|null $callback = true): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function hasAuthorization(): bool
    {
        return $this->authorizeUsing !== null;
    }

    public function isAuthorized(): bool
    {
        return $this->authorizeUsing === null || (bool) $this->evaluate($this->authorizeUsing);
    }

    /**
     * Whether the current user may `$ability` (`view`, `update`…) the site: the plugin's
     * `authorize()`, the tenant, and the `Site` policy when one is registered.
     */
    public function can(string $ability, ?Site $site = null): bool
    {
        if (! $this->isAuthorized()) {
            return false;
        }

        if ($site !== null && ! $site->isOwnedByTenant($this->getOwningTenant())) {
            return false;
        }

        if (Gate::getPolicyFor(Site::class) === null) {
            return true;
        }

        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();

        if (! $user instanceof Authorizable) {
            return false;
        }

        return Gate::forUser($user)->allows($ability, $site ?? Site::class);
    }

    // ---------------------------------------------------------------- Tenancy

    /**
     * Each tenant has its own sites on tenancy panels (default).
     */
    public function scopeToTenant(bool|Closure $condition = true): static
    {
        $this->isScopedToTenant = $condition;

        return $this;
    }

    public function isScopedToTenant(): bool
    {
        return (bool) $this->evaluate($this->isScopedToTenant);
    }

    /**
     * The tenant new sites belong to (and the only one whose sites are visible).
     */
    public function getOwningTenant(): ?Model
    {
        $panel = alfakit()->getCurrentPanel();

        if ($panel === null || ! $panel->hasTenancy() || ! $this->isScopedToTenant()) {
            return null;
        }

        return $panel->getTenant();
    }

    /**
     * The sites the current user may see in this panel (and tenant).
     *
     * @return Builder<Site>
     */
    public function getSitesQuery(): Builder
    {
        $query = Site::query();
        $panel = alfakit()->getCurrentPanel();

        if ($panel !== null && $panel->hasTenancy() && $this->isScopedToTenant()) {
            $tenant = $panel->getTenant();

            return $tenant === null ? $query->whereRaw('1 = 0') : Site::constrainToTenant($query, $tenant);
        }

        return $query;
    }

    // ---------------------------------------------------------------- Dashboard

    /**
     * Allow shared, read-only dashboard links (default). Off: the share settings are hidden
     * and existing links stop working.
     */
    public function sharing(bool|Closure $condition = true): static
    {
        $this->canShare = $condition;

        return $this;
    }

    public function canShare(): bool
    {
        return (bool) $this->evaluate($this->canShare);
    }

    /**
     * Rows shown in each top list.
     */
    public function topListLimit(int|Closure $limit): static
    {
        $this->topListLimit = $limit;

        return $this;
    }

    public function getTopListLimit(): int
    {
        return max(1, min(100, (int) $this->evaluate($this->topListLimit)));
    }

    /**
     * How often the KPIs and "visitors now" refresh on panels without `realtime()` (null
     * turns polling off). On `realtime()` panels they refresh when visits are recorded.
     */
    public function realtimePollingInterval(string|Closure|null $interval): static
    {
        $this->realtimePollingInterval = $interval;

        return $this;
    }

    public function getRealtimePollingInterval(): ?string
    {
        $interval = $this->evaluate($this->realtimePollingInterval);

        return is_string($interval) && $interval !== '' ? $interval : null;
    }

    // ---------------------------------------------------------------- Navigation

    public function navigationLabel(string|Closure|null $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        $label = $this->evaluate($this->navigationLabel);

        return is_string($label) && $label !== '' ? $label : (string) __('alfakit-analytics::analytics.navigation.label');
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) && $group !== '' ? $group : null;
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        $icon = $this->evaluate($this->navigationIcon);

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->evaluate($this->navigationSort);

        return is_int($sort) ? $sort : null;
    }

    public function registerNavigation(bool|Closure $condition = true): static
    {
        $this->shouldRegisterNavigation = $condition;

        return $this;
    }

    public function shouldRegisterNavigation(): bool
    {
        return (bool) $this->evaluate($this->shouldRegisterNavigation);
    }
}
