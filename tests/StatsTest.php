<?php

declare(strict_types=1);

use AlfaSix\AlfakitAnalytics\Models\DailyStat;
use AlfaSix\AlfakitAnalytics\Models\Event;
use AlfaSix\AlfakitAnalytics\Models\ReportRow;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Models\Visit;
use AlfaSix\AlfakitAnalytics\Support\Aggregator;
use AlfaSix\AlfakitAnalytics\Support\Dimension;
use AlfaSix\AlfakitAnalytics\Support\Filters;
use AlfaSix\AlfakitAnalytics\Support\Metrics;
use AlfaSix\AlfakitAnalytics\Support\Period;
use AlfaSix\AlfakitAnalytics\Support\Pruner;
use AlfaSix\AlfakitAnalytics\Support\Stats;
use AlfaSix\AlfakitAnalytics\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

use function AlfaSix\AlfakitAnalytics\Tests\hit;
use function AlfaSix\AlfakitAnalytics\Tests\site;

use const AlfaSix\AlfakitAnalytics\Tests\IPHONE;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

function analyticsAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

/**
 * Two days of traffic for a site in UTC+3:
 * - 2026-10-12: Google visitor (2 pages, signup), direct visitor (bounce), iPhone from Facebook (bounce)
 * - 2026-10-13: the Google visitor again (bounce on /pricing)
 */
function analyticsSeedTraffic(Site $site): void
{
    hit($site, 'https://example.com/', referrer: 'https://www.google.com/', ip: '10.0.0.1', at: analyticsAt('2026-10-12 07:00:00'));
    hit($site, 'https://example.com/pricing', ip: '10.0.0.1', at: analyticsAt('2026-10-12 07:01:00'));
    hit($site, 'https://example.com/pricing', 'Signup', ip: '10.0.0.1', at: analyticsAt('2026-10-12 07:02:00'), properties: ['plan' => 'pro']);
    hit($site, 'https://example.com/blog/hello', ip: '10.0.0.2', at: analyticsAt('2026-10-12 08:00:00'));
    hit($site, 'https://example.com/', referrer: 'https://m.facebook.com/', ip: '10.0.0.3', ua: IPHONE, at: analyticsAt('2026-10-12 09:00:00'));
    hit($site, 'https://example.com/pricing', referrer: 'https://www.google.com/', ip: '10.0.0.1', at: analyticsAt('2026-10-13 07:00:00'));
}

function analyticsTrafficSite(): Site
{
    return site(['timezone' => 'Asia/Bahrain', 'goals' => [
        ['type' => 'event', 'match' => 'Signup'],
        ['type' => 'page', 'match' => '/blog/*', 'name' => 'Read the blog'],
        ['type' => 'page', 'match' => '/never'],
    ]]);
}

function analyticsPeriod(string $from, string $to): Period
{
    return Period::custom($from, $to, 'Asia/Bahrain') ?? throw new RuntimeException('Bad period.');
}

/**
 * Every number of a period as plain arrays, for comparing raw and rolled-up results.
 *
 * @return array<string, mixed>
 */
function analyticsSnapshot(Stats $stats): array
{
    $dump = static fn (array $rows): array => array_map(static fn (Metrics $metrics): array => $metrics->toArray(), $rows);

    $breakdowns = [];

    foreach ([...Dimension::rolledUp(), Dimension::property('Signup', 'plan')] as $dimension) {
        $breakdowns[$dimension] = $dump($stats->breakdown($dimension, null));
    }

    return [
        'totals' => $stats->totals()->toArray(),
        'series' => $dump($stats->timeseries()),
        'breakdowns' => $breakdowns,
    ];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 12:00:00');
    CarbonImmutable::setTestNow('2026-10-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('computes totals, bounce rate, duration and breakdowns from raw data', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);

    $stats = Stats::for($site, analyticsPeriod('2026-10-12', '2026-10-13'));
    $totals = $stats->totals();

    // Visitors are unique per day: the Google visitor counts once on each day.
    expect($totals->visitors)->toBe(4)
        ->and($totals->visits)->toBe(4)
        ->and($totals->pageviews)->toBe(5)
        ->and($totals->events)->toBe(1)
        ->and($totals->bounces)->toBe(3)
        ->and($totals->bounceRate())->toBe(75.0)
        ->and($totals->visitDuration())->toBe(30)
        ->and(array_map(static fn (Metrics $m): int => $m->visitors, $stats->breakdown(Dimension::SOURCE)))->toBe(['Google' => 2, '' => 1, 'Facebook' => 1])
        ->and(array_map(static fn (Metrics $m): int => $m->pageviews, $stats->breakdown(Dimension::PAGE)))->toBe(['/' => 2, '/pricing' => 2, '/blog/hello' => 1])
        ->and(array_keys($stats->breakdown(Dimension::DEVICE)))->toBe(['desktop', 'mobile'])
        ->and(array_map(static fn (Metrics $m): int => $m->visitors, $stats->breakdown(Dimension::EXIT_PAGE)))->toBe(['/pricing' => 2, '/' => 1, '/blog/hello' => 1])
        ->and(array_map(static fn (Metrics $m): int => $m->events, $stats->breakdown(Dimension::EVENT)))->toBe(['Signup' => 1])
        ->and(array_map(static fn (Metrics $m): int => $m->visitors, $stats->breakdown(Dimension::property('Signup', 'plan'))))->toBe(['pro' => 1])
        ->and(array_map(static fn (Metrics $m): int => $m->visitors, $stats->breakdown(Dimension::GOAL)))->toBe(['Signup' => 1, 'Read the blog' => 1, '/never' => 0])
        ->and($stats->propertyKeys('Signup'))->toBe(['plan']);
});

it('writes rollups that give exactly the numbers of raw data', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);
    $period = analyticsPeriod('2026-10-12', '2026-10-13');

    $raw = analyticsSnapshot(Stats::for($site, $period));

    $aggregator = app(Aggregator::class);
    expect($aggregator->aggregateRange($site, '2026-10-12', '2026-10-13'))->toBeGreaterThan(0);

    $rolled = Stats::for($site, $period);

    expect($rolled->usesRollups())->toBeTrue()
        ->and($rolled->rolledUpDates())->toBe(['2026-10-12', '2026-10-13'])
        ->and(analyticsSnapshot($rolled))->toBe($raw);

    // Rollups alone (raw data gone) still give the same numbers.
    Event::query()->delete();
    Visit::query()->delete();

    expect(analyticsSnapshot(Stats::for($site, $period)))->toBe($raw);

    // Aggregating again replaces the day's rows.
    $count = DailyStat::query()->count();
    $aggregator->aggregate($site, '2026-10-12');

    expect(DailyStat::query()->count())->toBeLessThan($count);
});

it('merges rollups of past days with raw data of today', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);
    app(Aggregator::class)->aggregateRange($site, '2026-10-12', '2026-10-13');

    // Today (2026-10-15 in Bahrain) is never aggregated.
    hit($site, 'https://example.com/', ip: '10.0.0.9', at: analyticsAt('2026-10-15 10:00:00'));

    expect(app(Aggregator::class)->aggregate($site, '2026-10-15'))->toBe(0);

    $stats = Stats::for($site, analyticsPeriod('2026-10-12', '2026-10-15'));

    expect($stats->rolledUpDates())->toBe(['2026-10-12', '2026-10-13'])
        ->and($stats->totals()->visitors)->toBe(5)
        ->and($stats->timeseries()['2026-10-15']->visitors)->toBe(1)
        ->and($stats->timeseries()['2026-10-14']->visitors)->toBe(0);
});

it('groups days by the site timezone', function (): void {
    $site = site(['timezone' => 'America/New_York']);

    // 2026-10-14 02:00 UTC is still 2026-10-13 in New York.
    hit($site, ip: '10.0.0.1', at: analyticsAt('2026-10-14 02:00:00'));
    hit($site, ip: '10.0.0.2', at: analyticsAt('2026-10-14 05:00:00'));

    app(Aggregator::class)->aggregateRange($site, '2026-10-13', '2026-10-14');

    $rows = DailyStat::query()->where('dimension', '')->orderBy('date')->get();

    expect($rows->map(static fn (DailyStat $row): string => substr($row->date, 0, 10))->all())->toBe(['2026-10-13', '2026-10-14'])
        ->and($rows->pluck('visitors')->all())->toBe([1, 1]);

    $day = Stats::for($site, Period::custom('2026-10-13', '2026-10-13', 'America/New_York') ?? throw new RuntimeException);

    expect($day->totals()->visitors)->toBe(1);
});

it('charts the hours of a single day in the site timezone', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);

    $series = Stats::for($site, analyticsPeriod('2026-10-12', '2026-10-12'))->timeseries();

    expect($series)->toHaveCount(24)
        ->and($series['10']->visitors)->toBe(1)
        ->and($series['10']->pageviews)->toBe(2)
        ->and($series['11']->visitors)->toBe(1)
        ->and($series['12']->visitors)->toBe(1)
        ->and($series['07']->visitors)->toBe(0);
});

it('filters by visit columns, pages, events, goals and none', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);
    app(Aggregator::class)->aggregateRange($site, '2026-10-12', '2026-10-13');
    $period = analyticsPeriod('2026-10-12', '2026-10-13');

    $google = Stats::for($site, $period, Filters::from(['source' => 'Google']));

    expect($google->usesRollups())->toBeFalse()
        ->and($google->totals()->visitors)->toBe(2)
        ->and(array_keys($google->breakdown(Dimension::PAGE)))->toBe(['/pricing', '/'])
        ->and(Stats::for($site, $period, Filters::from(['source' => Dimension::NONE]))->totals()->visitors)->toBe(1)
        ->and(Stats::for($site, $period, Filters::from(['device' => 'mobile']))->breakdown(Dimension::SOURCE))->toHaveKey('Facebook')
        ->and(Stats::for($site, $period, Filters::from(['page' => '/blog/*']))->totals()->visitors)->toBe(1)
        ->and(Stats::for($site, $period, Filters::from(['page' => '/pricing']))->totals()->pageviews)->toBe(2)
        ->and(Stats::for($site, $period, Filters::from(['event' => 'Signup']))->totals()->visits)->toBe(1)
        ->and(Stats::for($site, $period, Filters::from(['goal' => 'Read the blog']))->totals()->visitors)->toBe(1)
        ->and(Stats::for($site, $period, Filters::from(['goal' => 'Unknown goal']))->totals()->visitors)->toBe(0)
        ->and(Stats::for($site, $period, Filters::from(['source' => 'Google', 'device' => 'mobile']))->totals()->visitors)->toBe(0);
});

it('ignores unknown filters and dimensions', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);

    expect(Filters::from(['visitor' => 'x', 'source' => ['array'], 'country' => 'BH'])->all())->toBe(['country' => 'BH'])
        ->and(Stats::for($site, analyticsPeriod('2026-10-12', '2026-10-13'))->breakdown('visitor; DROP TABLE'))->toBe([]);
});

it('compares with the previous period', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);

    $period = analyticsPeriod('2026-10-13', '2026-10-13');
    $previous = $period->previous();

    expect($previous->fromDate())->toBe('2026-10-12')
        ->and(Stats::for($site, $period)->previous()->totals()->visitors)->toBe(3)
        ->and(Metrics::change(1, 3))->toBe(-66.7)
        ->and(Metrics::change(3, 0))->toBeNull()
        ->and(Metrics::change(0, 0))->toBe(0.0)
        ->and(Period::preset('month', 'UTC', analyticsAt('2026-10-15 12:00:00'))->previous()->fromDate())->toBe('2026-09-01')
        ->and(Period::preset('month', 'UTC', analyticsAt('2026-10-15 12:00:00'))->previous()->toDate())->toBe('2026-09-15')
        ->and(Period::preset('last_month', 'UTC', analyticsAt('2026-10-15 12:00:00'))->previous()->toDate())->toBe('2026-08-31');
});

it('builds periods from presets and validates custom ranges', function (): void {
    $now = analyticsAt('2026-10-15 22:30:00');

    expect(Period::preset('today', 'Asia/Bahrain', $now)->fromDate())->toBe('2026-10-16')
        ->and(Period::preset('7d', 'UTC', $now)->fromDate())->toBe('2026-10-09')
        ->and(Period::preset('30d', 'UTC', $now)->days())->toBe(30)
        ->and(Period::preset('nonsense', 'UTC', $now)->preset)->toBe('30d')
        ->and(Period::custom('2026-02-30', '2026-03-01', 'UTC'))->toBeNull()
        ->and(Period::custom('2026-10-10', '2026-10-01', 'UTC')?->fromDate())->toBe('2026-10-01')
        ->and(Period::fromInput('custom', 'bad', null, 'UTC')->preset)->toBe('30d')
        ->and(Period::preset('12mo', 'UTC', $now)->interval())->toBe('month')
        ->and(Period::preset('today', 'UTC', $now)->interval())->toBe('hour');
});

it('counts current visitors seen in the last minutes', function (): void {
    $site = analyticsTrafficSite();
    hit($site, ip: '10.0.0.1', at: analyticsAt('2026-10-15 11:57:00'));
    hit($site, ip: '10.0.0.2', at: analyticsAt('2026-10-15 11:58:00'));
    hit($site, ip: '10.0.0.3', at: analyticsAt('2026-10-15 11:40:00'));

    expect(Stats::for($site, analyticsPeriod('2026-10-15', '2026-10-15'))->currentVisitors())->toBe(2);
});

it('prunes raw data past its retention after aggregating it, and old rollups and exports', function (): void {
    config()->set('alfakit-analytics.raw_retention_days', 2);
    config()->set('alfakit-analytics.rollup_retention_days', 365);
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);
    hit($site, ip: '10.0.0.9', at: analyticsAt('2026-10-15 10:00:00'));
    DailyStat::query()->insert(['site_id' => $site->id, 'date' => '2024-01-01', 'dimension' => '', 'value' => '', 'visitors' => 1]);
    ReportRow::query()->insert(['report' => 'old', 'site_id' => $site->id, 'position' => 1, 'dimension' => 'page', 'label' => '/', 'created_at' => now()->subDays(2)]);

    $before = analyticsSnapshot(Stats::for($site, analyticsPeriod('2026-10-12', '2026-10-13')));
    $result = app(Pruner::class)->prune();

    expect($result['raw'])->toBe(3)
        ->and($result['rollups'])->toBe(1)
        ->and($result['reports'])->toBe(1)
        ->and(Visit::query()->count())->toBe(2)
        ->and(analyticsSnapshot(Stats::for($site, analyticsPeriod('2026-10-12', '2026-10-13'))))->toBe($before);
});

it('aggregates and prunes from the scheduled command', function (): void {
    $site = analyticsTrafficSite();
    analyticsSeedTraffic($site);

    $this->artisan('alfakit-analytics:aggregate', ['--days' => 5])->assertSuccessful();

    expect(DailyStat::query()->where('dimension', '')->count())->toBe(5);

    $this->artisan('alfakit-analytics:aggregate', ['--from' => '2026-10-12', '--to' => '2026-10-12', '--site' => $site->key, '--no-prune' => true])->assertSuccessful();

    $events = app(Illuminate\Console\Scheduling\Schedule::class)->events();

    expect(collect($events)->contains(static fn ($event): bool => str_contains((string) $event->command, 'alfakit-analytics:aggregate')))->toBeTrue();
});
