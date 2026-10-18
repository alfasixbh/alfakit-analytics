<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Tests;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Support\Hit;
use AlfaSix\AlfakitAnalytics\Support\Recorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

function analyticsTest(): TestCase
{
    return TestCase::current();
}

function analyticsPanel(?AnalyticsPlugin $plugin = null, string $id = 'stats'): Panel
{
    return Panels::register($id, static fn (Panel $panel): Panel => $panel->login()->plugin($plugin ?? AnalyticsPlugin::make()));
}

function tenantAnalyticsPanel(?AnalyticsPlugin $plugin = null): Panel
{
    return Panels::register('stats-team', static fn (Panel $panel): Panel => $panel
        ->login()
        ->tenant(Team::class, slugAttribute: 'slug')
        ->plugin($plugin ?? AnalyticsPlugin::make()));
}

function user(string $name = 'Ada'): User
{
    return User::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'secret']);
}

function tenantUser(string $name = 'Ada'): TenantUser
{
    return TenantUser::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'secret']);
}

function team(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function site(array $attributes = []): Site
{
    $site = new Site;
    $site->fill(['name' => 'Example', 'domain' => 'example.com', ...$attributes]);
    $site->save();

    return $site;
}

/**
 * Record a hit as the snippet would, from a browser.
 *
 * @param  array<string, mixed>  $properties
 * @param  array<string, string>  $headers
 */
function hit(Site $site, string $url = 'https://example.com/', string $name = 'pageview', ?string $referrer = null, string $ip = '203.0.113.7', string $ua = CHROME, ?CarbonImmutable $at = null, array $properties = [], array $headers = []): ?\AlfaSix\AlfakitAnalytics\Models\Event
{
    $request = Request::create('https://example.com/alfakit-analytics/event', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);

    foreach ($headers as $header => $value) {
        $request->headers->set($header, $value);
    }

    return app(Recorder::class)->record($site, new Hit($name, $url, $referrer, $properties, $ip, $ua, $at, $request));
}

/**
 * Send a hit to the collection endpoint.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function collectHit(array $payload, array $headers = [], string $ip = '203.0.113.7'): TestResponse
{
    return analyticsTest()->call('POST', '/alfakit-analytics/event', server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => CHROME,
        'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        ...array_combine(array_map(static fn (string $name): string => 'HTTP_'.strtoupper(str_replace('-', '_', $name)), array_keys($headers)), array_values($headers)),
    ], content: (string) json_encode($payload));
}

/**
 * @return array<string, mixed>
 */
function inertiaProps(string $url): array
{
    $response = analyticsTest()->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
    $response->assertOk();

    return $response->json('props');
}

/**
 * The widgets of the dashboard, lazy ones loaded, keyed by type (`AnalyticsBreakdown` by panel).
 *
 * @return array<string, array<string, mixed>>
 */
function dashboardWidgets(string $url): array
{
    $props = inertiaProps($url);
    $component = analyticsTest()->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->json('component');
    $lazy = analyticsTest()->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => \AlfaSix\Alfakit\Pages\Page::LAZY_PROP,
    ])->json('props.'.\AlfaSix\Alfakit\Pages\Page::LAZY_PROP) ?? [];

    $widgets = [];

    foreach ($props['page']['content'] as $block) {
        if (($block['key'] ?? null) !== 'widgets') {
            continue;
        }

        foreach ($block['widgets'] as $widget) {
            $widget = ($widget['lazy'] ?? false) ? ($lazy['widget:'.$widget['id']] ?? $widget) : $widget;
            $key = $widget['type'] === 'AnalyticsBreakdown' ? 'panel:'.($widget['panel'] ?? '') : $widget['type'];
            $widgets[$key] = $widget;
        }
    }

    return $widgets;
}
