<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\AlfakitAnalytics\Analytics;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Models\ReportRow;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Pages\AnalyticsDashboard;
use AlfaSix\AlfakitAnalytics\Resources\SiteResource\Pages\CreateSite;
use AlfaSix\AlfakitAnalytics\Tests\Fixtures\SitePolicy;
use AlfaSix\AlfakitAnalytics\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

use function AlfaSix\AlfakitAnalytics\Tests\analyticsPanel;
use function AlfaSix\AlfakitAnalytics\Tests\analyticsTest;
use function AlfaSix\AlfakitAnalytics\Tests\dashboardWidgets;
use function AlfaSix\AlfakitAnalytics\Tests\hit;
use function AlfaSix\AlfakitAnalytics\Tests\inertiaProps;
use function AlfaSix\AlfakitAnalytics\Tests\site;
use function AlfaSix\AlfakitAnalytics\Tests\team;
use function AlfaSix\AlfakitAnalytics\Tests\tenantAnalyticsPanel;
use function AlfaSix\AlfakitAnalytics\Tests\tenantUser;
use function AlfaSix\AlfakitAnalytics\Tests\user;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 12:00:00');
    CarbonImmutable::setTestNow('2026-10-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function analyticsTrafficFor(Site $site): void
{
    hit($site, 'https://example.com/', referrer: 'https://www.google.com/', ip: '10.0.0.1', at: CarbonImmutable::parse('2026-10-14 09:00:00'));
    hit($site, 'https://example.com/pricing', ip: '10.0.0.1', at: CarbonImmutable::parse('2026-10-14 09:01:00'));
    hit($site, 'https://example.com/pricing', 'Signup', ip: '10.0.0.1', at: CarbonImmutable::parse('2026-10-14 09:02:00'), properties: ['plan' => 'pro']);
    hit($site, 'https://example.com/docs', ip: '10.0.0.2', at: CarbonImmutable::parse('2026-10-15 11:58:00'));
}

it('registers the dashboard page, the sites resource and the frontend strings', function (): void {
    $panel = analyticsPanel();

    expect($panel->hasPlugin('analytics'))->toBeTrue()
        ->and($panel->getFrontendTranslations()['analytics']['filter_by'])->toBe('Filter by :label');
});

it('shows an empty state without sites', function (): void {
    analyticsPanel();
    analyticsTest()->actingAs(user());

    $props = inertiaProps('/stats/analytics');

    expect(json_encode($props['page']['content']))->toContain('No sites yet')
        ->and(dashboardWidgets('/stats/analytics'))->toBe([]);
});

it('renders KPIs, the chart and top lists with filter links', function (): void {
    analyticsPanel();
    analyticsTest()->actingAs(user());
    $site = site(['goals' => [['type' => 'event', 'match' => 'Signup']]]);
    analyticsTrafficFor($site);

    $widgets = dashboardWidgets('/stats/analytics?period=7d&compare=1');
    $stats = collect($widgets['StatsOverviewWidget']['stats'])->keyBy(static fn (array $stat): string => $stat['extraAttributes']['data-analytics-stat']);

    expect($stats['current_visitors']['value'])->toBe('1')
        ->and($stats['visitors']['value'])->toBe('2')
        ->and($stats['pageviews']['value'])->toBe('3')
        ->and($stats['bounce_rate']['value'])->toContain('50')
        ->and($widgets['StatsOverviewWidget']['live']['fallbackPollingInterval'])->toBe('15s')
        ->and($widgets['Chart']['data']['labels'])->toHaveCount(7)
        ->and($widgets['Chart']['data']['datasets'])->toHaveCount(2);

    $sources = $widgets['panel:sources'];

    expect($sources['component'])->toBe('analytics:breakdown')
        ->and(array_column($sources['tabs'], 'key'))->toBe(['source', 'channel', 'referrer'])
        ->and($sources['tabs'][0]['rows'][0]['label'])->toBe('Google')
        ->and($sources['tabs'][0]['rows'][1]['label'])->toBe('Direct / None')
        ->and(urldecode($sources['tabs'][0]['rows'][0]['url']))->toContain('filter[source]=Google')
        ->and(urldecode($sources['tabs'][0]['rows'][1]['url']))->toContain('filter[source]=(none)')
        ->and($widgets['panel:goals']['tabs'][0]['rows'][0]['display'][1])->toContain('50');

    // Filtered by Google: only that visitor, the filter is active and the header can remove it.
    $filtered = dashboardWidgets('/stats/analytics?period=7d&filter[source]=Google');
    $props = inertiaProps('/stats/analytics?period=7d&filter[source]=Google');

    expect($filtered['panel:pages']['tabs'][0]['rows'])->toHaveCount(2)
        ->and($filtered['panel:sources']['tabs'][0]['rows'][0]['active'])->toBeTrue()
        ->and(urldecode($filtered['panel:sources']['tabs'][0]['rows'][0]['url']))->not->toContain('filter[source]')
        ->and(json_encode($props['page']['headerActions']))->toContain('Source is Google');

    // An event filter adds the property tabs of that event.
    $events = dashboardWidgets('/stats/analytics?period=7d&filter[event]=Signup')['panel:events'];

    expect(array_column($events['tabs'], 'key'))->toBe(['event', 'prop:Signup:plan'])
        ->and($events['activeTab'])->toBe('prop:Signup:plan')
        ->and($events['tabs'][1]['rows'][0]['label'])->toBe('pro');
});

it('only offers the sites the user may view and keeps others out of reach', function (): void {
    analyticsPanel();
    analyticsTest()->actingAs($user = user());
    $mine = site(['name' => 'Mine']);
    $hidden = site(['name' => 'Hidden', 'domain' => 'hidden.test']);

    Gate::policy(Site::class, SitePolicy::class);

    $props = inertiaProps('/stats/analytics?site='.$hidden->getKey());

    expect($props['page']['subheading'])->toContain('example.com')
        ->and(json_encode($props['page']['headerActions'] ?? []))->not->toContain('Hidden');
});

it('denies the dashboard and the resource when authorize() says no', function (): void {
    analyticsPanel(AnalyticsPlugin::make()->authorize(static fn ($user): bool => $user?->name === 'Admin'));

    analyticsTest()->actingAs(user('Guest'));
    analyticsTest()->get('/stats/analytics')->assertForbidden();
    analyticsTest()->get('/stats/analytics-sites')->assertForbidden();

    analyticsTest()->actingAs(user('Admin'));
    analyticsTest()->get('/stats/analytics', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();
});

it('scopes sites to the current tenant', function (): void {
    tenantAnalyticsPanel();
    $alpha = team('Alpha');
    $beta = team('Beta');
    $user = tenantUser();
    $user->teams()->attach([$alpha->id, $beta->id]);
    analyticsTest()->actingAs($user);

    $alphaSite = site(['name' => 'Alpha site'])->setTenant($alpha);
    $alphaSite->save();
    $betaSite = site(['name' => 'Beta site', 'domain' => 'beta.test'])->setTenant($beta);
    $betaSite->save();
    hit($betaSite, 'https://beta.test/secret-page', at: CarbonImmutable::parse('2026-10-15 11:00:00'));

    // Asking for Beta's site from Alpha falls back to Alpha's own site.
    $widgets = dashboardWidgets('/stats-team/alpha/analytics?site='.$betaSite->getKey().'&period=today');

    expect(json_encode($widgets))->not->toContain('secret-page')
        ->and(inertiaProps('/stats-team/alpha/analytics')['page']['subheading'])->toContain('example.com');

    // The resource lists and opens Alpha's sites only.
    $list = json_encode(inertiaProps('/stats-team/alpha/analytics-sites'));
    expect($list)->toContain('Alpha site')->not->toContain('Beta site');

    analyticsTest()->get('/stats-team/alpha/analytics-sites/'.$betaSite->getKey().'/edit', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertNotFound();
});

it('creates sites for the current tenant with goals and normalised domains', function (): void {
    tenantAnalyticsPanel();
    $alpha = team('Alpha');
    $user = tenantUser();
    $user->teams()->attach($alpha);
    analyticsTest()->actingAs($user);

    alfakit()->testPage(CreateSite::class, ['tenant' => $alpha], panel: 'stats-team')
        ->fillForm([
            'name' => 'Shop',
            'domain' => 'https://www.Shop.example/',
            'domains' => ['https://eu.shop.example', 'EU.shop.example'],
            'timezone' => 'Asia/Bahrain',
            'is_active' => true,
            'goals' => [['type' => 'event', 'match' => 'Purchase', 'name' => ''], ['type' => 'page', 'match' => '/thanks/', 'name' => 'Thank you']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()->sole();

    expect($site->isOwnedByTenant($alpha))->toBeTrue()
        ->and($site->domain)->toBe('shop.example')
        ->and($site->domains)->toBe(['eu.shop.example'])
        ->and($site->goals)->toBe([['name' => 'Purchase', 'type' => 'event', 'match' => 'Purchase'], ['name' => 'Thank you', 'type' => 'page', 'match' => '/thanks']])
        ->and($site->key)->toHaveLength(20);
});

it('exports a top list through the exporter with the current filters', function (): void {
    Storage::fake('local');
    analyticsPanel();
    analyticsTest()->actingAs(user());
    $site = site();
    analyticsTrafficFor($site);

    alfakit()->testPage(AnalyticsDashboard::class, panel: 'stats')
        ->callAction('exportTopList', ['dimension' => 'page'])
        ->assertHasNoActionErrors();

    $export = Export::query()->sole();

    expect(ReportRow::query()->orderBy('position')->pluck('label')->all())->toBe(['/', '/docs', '/pricing'])
        ->and($export->total_rows)->toBe(3)
        ->and($export->isCompleted())->toBeTrue();

    // Unknown lists are rejected.
    alfakit()->testPage(AnalyticsDashboard::class, panel: 'stats')
        ->callAction('exportTopList', ['dimension' => 'visitor'])
        ->assertHasActionErrors(['dimension']);

    expect(Export::query()->count())->toBe(1);
});

it('shows the opt-in "Powered by" credit on shared dashboards only when it is on', function (): void {
    $site = site(['is_shared' => true]);
    $url = (string) Analytics::shareUrl($site);

    analyticsTest()->get($url)->assertOk()->assertDontSee('data-slot="powered-by"', false);

    config(['alfakit.credit.public_pages' => true]);

    analyticsTest()->get($url)->assertOk()->assertSee('data-slot="powered-by"', false);
});

it('serves the shared dashboard from a signed link and revokes it', function (): void {
    $site = site(['is_shared' => true]);
    analyticsTrafficFor($site);

    $url = Analytics::shareUrl($site);

    expect($url)->not->toBeNull();

    analyticsTest()->get((string) $url)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Example')
        ->assertSee('/pricing');

    // Period and filter links keep working; a tampered link does not.
    analyticsTest()->get($url.'&period=7d&filter[source]=Google')->assertOk()->assertSee('Source is Google');
    analyticsTest()->get(str_replace('token=', 'token=x', (string) $url))->assertForbidden();
    analyticsTest()->get('/alfakit-analytics/share/'.$site->getKey().'?token='.$site->share_token)->assertForbidden();

    // Expiring links work until they expire.
    $temporary = (string) Analytics::shareUrl($site, now()->addHour());
    analyticsTest()->get($temporary.'&period=today')->assertOk();
    Carbon::setTestNow(now()->addHours(2));
    analyticsTest()->get($temporary)->assertForbidden();
    Carbon::setTestNow('2026-10-15 12:00:00');

    // Rotating the token or turning sharing off revokes the link.
    $site->rotateShareToken();
    analyticsTest()->get((string) $url)->assertNotFound();

    $fresh = (string) Analytics::shareUrl($site);
    $site->update(['is_shared' => false]);
    analyticsTest()->get($fresh)->assertNotFound();

    expect(Analytics::shareUrl($site))->toBeNull();
});

it('shows the share link only to users who may update the site', function (): void {
    analyticsPanel();
    analyticsTest()->actingAs(user());
    site(['is_shared' => true]);

    expect(json_encode(inertiaProps('/stats/analytics')['page']['headerActions']))->toContain('Share link');

    analyticsPanel(AnalyticsPlugin::make()->sharing(false), 'stats-private');

    expect(json_encode(inertiaProps('/stats-private/analytics')['page']['headerActions']))->not->toContain('Share link');
});
