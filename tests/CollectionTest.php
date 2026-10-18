<?php

declare(strict_types=1);

use AlfaSix\AlfakitAnalytics\Analytics;
use AlfaSix\AlfakitAnalytics\Contracts\ResolvesCountry;
use AlfaSix\AlfakitAnalytics\Models\Event;
use AlfaSix\AlfakitAnalytics\Models\EventProperty;
use AlfaSix\AlfakitAnalytics\Models\Salt;
use AlfaSix\AlfakitAnalytics\Models\Visit;
use AlfaSix\AlfakitAnalytics\Support\PageUrl;
use AlfaSix\AlfakitAnalytics\Support\Referrer;
use AlfaSix\AlfakitAnalytics\Support\Salts;
use AlfaSix\AlfakitAnalytics\Support\UserAgent;
use AlfaSix\AlfakitAnalytics\Support\VisitorHash;
use AlfaSix\AlfakitAnalytics\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function AlfaSix\AlfakitAnalytics\Tests\analyticsTest;
use function AlfaSix\AlfakitAnalytics\Tests\collectHit;
use function AlfaSix\AlfakitAnalytics\Tests\hit;
use function AlfaSix\AlfakitAnalytics\Tests\site;

use const AlfaSix\AlfakitAnalytics\Tests\CHROME;
use const AlfaSix\AlfakitAnalytics\Tests\IPHONE;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

afterEach(fn () => CarbonImmutable::setTestNow());

// ------------------------------------------------------------ Parsing

it('parses browsers, operating systems and devices from user agents', function (string $ua, ?string $browser, ?string $os, string $device): void {
    $agent = UserAgent::parse($ua);

    expect($agent->isBot)->toBeFalse()
        ->and($agent->browser)->toBe($browser)
        ->and($agent->os)->toBe($os)
        ->and($agent->device)->toBe($device);
})->with([
    'chrome mac' => [CHROME, 'Chrome', 'macOS', 'desktop'],
    'safari iphone' => [IPHONE, 'Safari', 'iOS', 'mobile'],
    'firefox windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0', 'Firefox', 'Windows', 'desktop'],
    'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0', 'Edge', 'Windows', 'desktop'],
    'samsung tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Safari/537.36', 'Samsung Internet', 'Android', 'tablet'],
    'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'tablet'],
]);

it('recognises bots, crawlers and HTTP libraries', function (string $ua): void {
    expect(UserAgent::parse($ua)->isBot)->toBeTrue();
})->with([
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36',
    'curl/8.4.0',
    'python-requests/2.32.3',
    'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    '',
    'Mozilla',
]);

it('honours extra bot patterns from the config', function (): void {
    config()->set('alfakit-analytics.bot_patterns', ['acme-monitor']);
    $site = site();

    expect(hit($site, ua: CHROME.' acme-monitor/1.0'))->toBeNull()
        ->and(hit($site))->not->toBeNull();
});

it('parses referrers into sources and channels', function (?string $referrer, array $utm, ?string $source, string $channel, ?string $page): void {
    $parsed = Referrer::parse($referrer, $utm, static fn (string $host): bool => $host === 'example.com');

    expect($parsed->source)->toBe($source)
        ->and($parsed->channel)->toBe($channel)
        ->and($parsed->referrer)->toBe($page);
})->with([
    'direct' => [null, [], null, 'direct', null],
    'google search' => ['https://www.google.co.uk/search?q=secret', [], 'Google', 'organic_search', 'google.co.uk/search'],
    'bing' => ['https://www.bing.com/', [], 'Bing', 'organic_search', 'bing.com'],
    'facebook mobile' => ['https://m.facebook.com/story.php?id=1', [], 'Facebook', 'social', 'm.facebook.com/story.php'],
    't.co' => ['https://t.co/abc', [], 'X', 'social', 't.co/abc'],
    'chatgpt' => ['https://chatgpt.com/', [], 'ChatGPT', 'ai', 'chatgpt.com'],
    'gmail' => ['https://mail.google.com/mail/u/0/', [], 'Gmail', 'email', 'mail.google.com/mail/u/0'],
    'unknown blog' => ['https://blog.acme.dev/post/1?ref=x', [], 'blog.acme.dev', 'referral', 'blog.acme.dev/post/1'],
    'internal' => ['https://example.com/pricing', [], null, 'direct', null],
    'utm newsletter' => [null, ['utm_source' => 'newsletter', 'utm_medium' => 'email'], 'Newsletter', 'email', null],
    'utm paid google' => ['https://www.google.com/', ['utm_source' => 'google', 'utm_medium' => 'cpc'], 'Google', 'paid', 'google.com'],
    'utm unknown source' => [null, ['utm_source' => 'podcast'], 'podcast', 'campaign', null],
    'not a url' => ['android-app://com.google.android.gm', [], null, 'direct', null],
]);

it('keeps only the path and campaign parameters of page URLs', function (): void {
    $page = PageUrl::parse('https://Example.com/blog/hello/?utm_source=hn&utm_campaign=launch&email=ada@example.com#top');

    expect($page?->hostname)->toBe('example.com')
        ->and($page?->pathname)->toBe('/blog/hello')
        ->and($page?->utm['utm_source'])->toBe('hn')
        ->and($page?->utm['utm_campaign'])->toBe('launch')
        ->and(PageUrl::parse('https://example.com')?->pathname)->toBe('/')
        ->and(PageUrl::parse('https://example.com/?ref=producthunt')?->utm['utm_source'])->toBe('producthunt')
        ->and(PageUrl::parse('javascript:alert(1)'))->toBeNull()
        ->and(PageUrl::parse('/relative'))->toBeNull();
});

// ------------------------------------------------------------ Privacy

it('stores no IP address, user agent, query string or other personal data', function (): void {
    $site = site();
    hit($site, 'https://example.com/account?email=ada%40example.com&token=s3cret', referrer: 'https://news.example.org/item?id=42&user=ada', ip: '198.51.100.23');

    $dump = json_encode([
        DB::table('alfakit_analytics_visits')->get(),
        DB::table('alfakit_analytics_events')->get(),
    ]);

    expect($dump)->not->toContain('198.51.100.23')
        ->not->toContain('Mozilla')
        ->not->toContain('ada')
        ->not->toContain('s3cret')
        ->not->toContain('id=42')
        ->and(Visit::query()->sole()->visitor)->toMatch('/^[0-9a-f]{16}$/')
        ->and(Visit::query()->sole()->referrer)->toBe('news.example.org/item')
        ->and(Event::query()->sole()->pathname)->toBe('/account');
});

it('hashes visitors per site with a salt that rotates daily and deletes old salts', function (): void {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    Illuminate\Support\Carbon::setTestNow('2026-10-14 10:00:00');
    $a = site();
    $b = site(['name' => 'Other', 'domain' => 'other.test']);

    $first = VisitorHash::make($a, '203.0.113.7', CHROME);

    expect(VisitorHash::make($a, '203.0.113.7', CHROME))->toBe($first)
        ->and(VisitorHash::make($b, '203.0.113.7', CHROME))->not->toBe($first)
        ->and(VisitorHash::make($a, '203.0.113.8', CHROME))->not->toBe($first)
        ->and(VisitorHash::make($a, '203.0.113.7', IPHONE))->not->toBe($first)
        ->and(Salt::query()->count())->toBe(1);

    $oldSalt = Salt::query()->sole()->salt;

    // The next UTC day: a new salt, the old one is gone, so the same browser gets a new id.
    Illuminate\Support\Carbon::setTestNow('2026-10-15 00:00:01');

    expect(VisitorHash::make($a, '203.0.113.7', CHROME))->not->toBe($first)
        ->and(Salt::query()->count())->toBe(1)
        ->and(Salt::query()->sole()->salt)->not->toBe($oldSalt)
        ->and(VisitorHash::make($a, '203.0.113.7', CHROME, $oldSalt))->toBe($first);

    Illuminate\Support\Carbon::setTestNow();
});

it('rotates and deletes expired salts from the scheduled command even without traffic', function (): void {
    Illuminate\Support\Carbon::setTestNow('2026-10-14 10:00:00');
    Salts::current();
    Illuminate\Support\Carbon::setTestNow('2026-10-16 10:00:00');

    expect(Salts::rotateIfDue())->toBeTrue()
        ->and(Salt::query()->count())->toBe(1)
        ->and(Salt::query()->sole()->created_at->toDateString())->toBe('2026-10-16');

    Illuminate\Support\Carbon::setTestNow();
});

it('drops hits of browsers that send Do Not Track or Global Privacy Control', function (array $headers): void {
    $site = site();

    expect(hit($site, headers: $headers))->toBeNull()
        ->and(Event::query()->count())->toBe(0);

    collectHit(['k' => $site->key, 'u' => 'https://example.com/'], $headers)->assertStatus(202);

    expect(Event::query()->count())->toBe(0);
})->with([
    'dnt' => [['DNT' => '1']],
    'gpc' => [['Sec-GPC' => '1']],
]);

it('can be told to record opted-out browsers', function (): void {
    config()->set('alfakit-analytics.respect_do_not_track', false);

    expect(hit(site(), headers: ['DNT' => '1']))->not->toBeNull();
});

it('serves a snippet under 2 KB that never uses cookies or storage and honours DNT and GPC', function (): void {
    $response = analyticsTest()->get('/alfakit-analytics/script.js');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

    $script = $response->getContent();

    expect(strlen((string) $script))->toBeLessThan(2048)
        ->and($script)->not->toContain('document.cookie')
        ->not->toContain('localStorage')
        ->not->toContain('sessionStorage')
        ->toContain('doNotTrack')
        ->toContain('globalPrivacyControl')
        ->toContain('sendBeacon');

    analyticsTest()->get('/alfakit-analytics/script.js', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});

it('renders the script tag with the Blade directive and the helper', function (): void {
    $site = site();
    config()->set('alfakit-analytics.default_site', $site->key);

    $html = Illuminate\Support\Facades\Blade::render('@alfakitAnalytics');

    expect($html)->toContain('src="/alfakit-analytics/script.js"')
        ->toContain('data-site="'.$site->key.'"')
        ->toContain('data-api="/alfakit-analytics/event"')
        ->and((string) Analytics::script($site, ['nonce' => 'abc"', 'data-local' => true, 'onload' => false]))
        ->toContain('nonce="abc&quot;"')->toContain(' data-local')->not->toContain('onload')
        ->and(Illuminate\Support\Facades\Blade::render("@alfakitAnalytics('{$site->key}')"))->toContain($site->key);
});

// ------------------------------------------------------------ Recording

it('records page views and custom events into visits', function (): void {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $site = site();

    hit($site, 'https://example.com/?utm_source=newsletter&utm_medium=email&utm_campaign=october', referrer: 'https://mail.google.com/');
    CarbonImmutable::setTestNow('2026-10-14 10:02:30');
    hit($site, 'https://www.example.com/pricing');
    CarbonImmutable::setTestNow('2026-10-14 10:03:00');
    hit($site, 'https://example.com/pricing', 'Signup', properties: ['plan' => 'pro', 'trial' => true, 'nested' => ['x'], 'empty' => '']);

    $visit = Visit::query()->sole();

    expect($visit->pageviews)->toBe(2)
        ->and($visit->events)->toBe(1)
        ->and($visit->is_bounce)->toBeFalse()
        ->and($visit->duration)->toBe(180)
        ->and($visit->entry_page)->toBe('/')
        ->and($visit->exit_page)->toBe('/pricing')
        ->and($visit->source)->toBe('Newsletter')
        ->and($visit->channel)->toBe('email')
        ->and($visit->utm_campaign)->toBe('october')
        ->and($visit->device)->toBe('desktop')
        ->and($visit->browser)->toBe('Chrome')
        ->and($visit->os)->toBe('macOS')
        ->and(Event::query()->orderBy('id')->pluck('name')->all())->toBe(['pageview', 'pageview', 'Signup'])
        ->and(EventProperty::query()->orderBy('key')->pluck('value', 'key')->all())->toBe(['plan' => 'pro', 'trial' => 'true']);
});

it('starts a new visit after the session timeout', function (): void {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $site = site();
    hit($site);
    CarbonImmutable::setTestNow('2026-10-14 10:31:00');
    hit($site, 'https://example.com/again');

    expect(Visit::query()->count())->toBe(2)
        ->and(Visit::query()->pluck('is_bounce')->all())->toBe([true, true]);
});

it('stores local dates and hours in the site timezone', function (): void {
    $site = site(['timezone' => 'Asia/Karachi']);

    hit($site, at: CarbonImmutable::parse('2026-10-13 22:30:00', 'UTC'));

    $event = Event::query()->sole();

    expect(substr($event->date, 0, 10))->toBe('2026-10-14')
        ->and($event->hour)->toBe(3)
        ->and(substr(Visit::query()->sole()->date, 0, 10))->toBe('2026-10-14');
});

it('ignores hits from other hostnames, paused sites and excluded paths', function (): void {
    $site = site(['domains' => ['*.shop.example.com']]);

    expect(hit($site, 'https://evil.test/'))->toBeNull()
        ->and(hit($site, 'https://eu.shop.example.com/cart'))->not->toBeNull()
        ->and(hit($site, 'https://www.example.com/'))->not->toBeNull();

    config()->set('alfakit-analytics.exclude_paths', ['/admin*']);
    expect(hit($site, 'https://example.com/admin/users'))->toBeNull();

    $site->update(['is_active' => false]);
    expect(hit($site->refresh()))->toBeNull();
});

it('resolves countries through the bound resolver or the configured header', function (): void {
    $site = site();
    config()->set('alfakit-analytics.country_header', 'CF-IPCountry');

    hit($site, headers: ['CF-IPCountry' => 'bh']);
    expect(Visit::query()->latest('id')->first()?->country)->toBe('BH');

    app()->bind(ResolvesCountry::class, static fn () => new class implements ResolvesCountry
    {
        public function resolve(?string $ip, ?Request $request): ?string
        {
            return $ip === '192.0.2.1' ? 'DE' : 'XX';
        }
    });

    hit($site, ip: '192.0.2.1');
    hit($site, ip: '192.0.2.2');

    expect(Visit::query()->orderBy('id')->pluck('country')->all())->toBe(['BH', 'DE', null]);
});

// ------------------------------------------------------------ Endpoint

it('accepts hits from the snippet on the collection endpoint', function (): void {
    $site = site();

    collectHit(['k' => $site->key, 'n' => 'pageview', 'u' => 'https://example.com/docs?utm_source=hn', 'r' => 'https://news.ycombinator.com/'])
        ->assertStatus(202)
        ->assertHeader('Access-Control-Allow-Origin', '*');

    collectHit(['k' => $site->key, 'n' => 'Download', 'u' => 'https://example.com/docs', 'p' => ['file' => 'guide.pdf']])->assertStatus(202);

    expect(Visit::query()->sole()->source)->toBe('Hacker News')
        ->and(Event::query()->count())->toBe(2)
        ->and(EventProperty::query()->sole()->value)->toBe('guide.pdf');

    analyticsTest()->call('OPTIONS', '/alfakit-analytics/event')->assertStatus(204)->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
});

it('validates hits', function (array $payload): void {
    site(['domain' => null]);

    collectHit($payload)->assertStatus(422);

    expect(Event::query()->count())->toBe(0);
})->with([
    'no key' => [['u' => 'https://example.com/']],
    'no url' => [['k' => 'whatever']],
    'not http' => [['k' => 'whatever', 'u' => 'ftp://example.com/']],
    'long name' => [['k' => 'whatever', 'u' => 'https://example.com/', 'n' => str_repeat('a', 65)]],
    'nested props' => [['k' => 'whatever', 'u' => 'https://example.com/', 'n' => 'X', 'p' => ['a' => ['b' => 1]]]],
    'too many props' => [['k' => 'whatever', 'u' => 'https://example.com/', 'n' => 'X', 'p' => array_fill_keys(range('a', 'z'), 1) + ['aa' => 1, 'ab' => 1, 'ac' => 1, 'ad' => 1, 'ae' => 1]]],
]);

it('answers 404 for unknown sites and 413 for huge bodies', function (): void {
    collectHit(['k' => 'nope', 'u' => 'https://example.com/'])->assertNotFound();

    $site = site();
    collectHit(['k' => $site->key, 'u' => 'https://example.com/', 'p' => ['x' => str_repeat('a', 9000)]])->assertStatus(413);
});

it('drops bot hits silently on the endpoint', function (): void {
    $site = site();

    analyticsTest()->call('POST', '/alfakit-analytics/event', server: ['HTTP_USER_AGENT' => 'curl/8.0', 'CONTENT_TYPE' => 'text/plain'], content: (string) json_encode(['k' => $site->key, 'u' => 'https://example.com/']))
        ->assertStatus(202);

    expect(Event::query()->count())->toBe(0);
});

it('rate limits the collection endpoint per IP address', function (): void {
    config()->set('alfakit-analytics.rate_limit', 3);
    $site = site();

    foreach (range(1, 3) as $attempt) {
        collectHit(['k' => $site->key, 'u' => 'https://example.com/'])->assertStatus(202);
    }

    collectHit(['k' => $site->key, 'u' => 'https://example.com/'])->assertStatus(429);
    collectHit(['k' => $site->key, 'u' => 'https://example.com/'], ip: '203.0.113.99')->assertStatus(202);

    expect(Event::query()->count())->toBe(4);
});

it('does not start a session or set cookies on the collection endpoint', function (): void {
    $site = site();

    $response = collectHit(['k' => $site->key, 'u' => 'https://example.com/']);

    expect($response->headers->getCookies())->toBe([]);
});

// ------------------------------------------------------------ Server side

it('tracks server-side events and page views for the current visitor', function (): void {
    $site = site();
    config()->set('alfakit-analytics.default_site', $site->key);

    Route::middleware('alfakit-analytics')->get('/landing', static fn () => response('<html><body>Hi</body></html>', 200, ['Content-Type' => 'text/html']));
    Route::post('/signup', static function () {
        Analytics::track('Signup', ['plan' => 'pro']);

        return response()->noContent();
    });
    Route::middleware('alfakit-analytics')->get('/api/data', static fn () => response()->json(['ok' => true]));

    analyticsTest()->withServerVariables(['HTTP_USER_AGENT' => CHROME])->get('http://example.com/landing?utm_source=ads&utm_medium=cpc')->assertOk();
    analyticsTest()->withServerVariables(['HTTP_USER_AGENT' => CHROME])->get('http://example.com/landing', ['Purpose' => 'prefetch'])->assertOk();
    analyticsTest()->withServerVariables(['HTTP_USER_AGENT' => CHROME])->get('http://example.com/api/data')->assertOk();
    analyticsTest()->withServerVariables(['HTTP_USER_AGENT' => CHROME])->post('http://example.com/signup')->assertNoContent();

    $visit = Visit::query()->sole();

    expect(Event::query()->orderBy('id')->pluck('name')->all())->toBe(['pageview', 'Signup'])
        ->and($visit->channel)->toBe('paid')
        ->and($visit->events)->toBe(1)
        ->and(EventProperty::query()->sole()->value)->toBe('pro');
});

it('records nothing without a site', function (): void {
    expect(Analytics::track('Signup'))->toBeNull()
        ->and(Analytics::site('missing'))->toBeNull();
});
