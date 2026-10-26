<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use AlfaSix\AlfakitAnalytics\Contracts\ResolvesCountry;
use AlfaSix\AlfakitAnalytics\Models\Event;
use AlfaSix\AlfakitAnalytics\Models\EventProperty;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records hits: drops bots, opted-out browsers, foreign hostnames and excluded paths,
 * hashes the visitor with the daily salt, starts or extends the visit and stores the
 * event with its properties. Nothing that identifies a person is written.
 */
class Recorder
{
    /** Longest custom event name. */
    public const MAX_NAME_LENGTH = 64;

    public function __construct(protected ResolvesCountry $countries) {}

    public function record(Site $site, Hit $hit): ?Event
    {
        if (! $site->is_active || ! $this->shouldRecord($hit)) {
            return null;
        }

        $name = self::normalizeName($hit->name);
        $page = PageUrl::parse($hit->url);

        if ($name === null || $page === null || (! $hit->isServerSide && ! $site->allowsHostname($page->hostname)) || $this->isExcludedPath($page->pathname)) {
            return null;
        }

        $agent = UserAgent::parse($hit->userAgent, $this->botPatterns());

        if ($agent->isBot && ! $hit->isServerSide) {
            return null;
        }

        // Stored in the application timezone, like every Eloquent timestamp.
        $occurredAt = ($hit->occurredAt ?? CarbonImmutable::now())->setTimezone(date_default_timezone_get());
        $local = $occurredAt->setTimezone($site->getTimezone());
        $visitor = VisitorHash::make($site, $hit->ip, $hit->userAgent);
        $isPageview = $name === Event::PAGEVIEW;

        $store = function () use ($site, $hit, $name, $page, $agent, $occurredAt, $local, $visitor, $isPageview): Event {
            $visit = $this->findOpenVisit($site, $visitor, $occurredAt);

            if ($visit === null) {
                $referrer = Referrer::parse($hit->referrer, $page->utm, static fn (string $host): bool => $host === Site::normalizeHostname($page->hostname) || ($site->getHostnames() !== [] && $site->allowsHostname($host)));

                $visit = new Visit;
                $visit->forceFill([
                    'site_id' => $site->getKey(),
                    'visitor' => $visitor,
                    'date' => $local->toDateString(),
                    'started_at' => $occurredAt,
                    'last_seen_at' => $occurredAt,
                    'duration' => 0,
                    'pageviews' => $isPageview ? 1 : 0,
                    'events' => $isPageview ? 0 : 1,
                    'is_bounce' => $isPageview,
                    'hostname' => mb_substr($page->hostname, 0, 255),
                    'entry_page' => $page->pathname,
                    'exit_page' => $page->pathname,
                    'source' => $referrer->source,
                    'channel' => $referrer->channel,
                    'referrer' => $referrer->referrer,
                    'utm_source' => $page->utm['utm_source'] ?? null,
                    'utm_medium' => $page->utm['utm_medium'] ?? null,
                    'utm_campaign' => $page->utm['utm_campaign'] ?? null,
                    'utm_content' => $page->utm['utm_content'] ?? null,
                    'utm_term' => $page->utm['utm_term'] ?? null,
                    'country' => $this->resolveCountry($hit),
                    'device' => $agent->device,
                    'browser' => $agent->browser,
                    'os' => $agent->os,
                ]);
                $visit->save();
            } else {
                $pageviews = $visit->pageviews + ($isPageview ? 1 : 0);
                $events = $visit->events + ($isPageview ? 0 : 1);
                $lastSeen = $occurredAt->greaterThan($visit->last_seen_at) ? $occurredAt : CarbonImmutable::instance($visit->last_seen_at);

                $visit->forceFill([
                    'last_seen_at' => $lastSeen,
                    'duration' => max(0, (int) $visit->started_at->diffInSeconds($lastSeen, true)),
                    'pageviews' => $pageviews,
                    'events' => $events,
                    // A bounce is a visit of a single page view without any interaction.
                    'is_bounce' => $pageviews <= 1 && $events === 0,
                    'exit_page' => $isPageview ? $page->pathname : $visit->exit_page,
                ]);
                $visit->save();
            }

            $event = new Event;
            $event->forceFill([
                'site_id' => $site->getKey(),
                'visit_id' => $visit->getKey(),
                'visitor' => $visitor,
                'name' => $name,
                'date' => $local->toDateString(),
                'hour' => (int) $local->format('G'),
                'occurred_at' => $occurredAt,
                'pathname' => $page->pathname,
                'hostname' => mb_substr($page->hostname, 0, 255),
            ]);
            $event->save();

            if (! $isPageview) {
                $this->storeProperties($site, $event, $hit->properties, $local);
            }

            return $event;
        };

        // SQLite refuses a second writer at once ("database is locked", without waiting when a
        // read inside the transaction has to become a write), so of two hits of one visitor close
        // together one was lost. Try again shortly, a few times.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($store);
            } catch (QueryException|DeadlockException $e) {
                if ($attempt >= 3 || ! str_contains($e->getMessage(), 'database is locked')) {
                    throw $e;
                }

                usleep(50_000 * $attempt);
            }
        }
    }

    public function shouldRecord(Hit $hit): bool
    {
        return ! (config('alfakit-analytics.respect_do_not_track', true) && $hit->optedOut());
    }

    /**
     * A custom event name (trimmed, at most 64 characters), `pageview` for page views, or
     * null when it is empty.
     */
    public static function normalizeName(string $name): ?string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');

        if ($name === '') {
            return null;
        }

        return strtolower($name) === Event::PAGEVIEW ? Event::PAGEVIEW : mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * Scalar properties only: at most `max_properties`, keys up to 64 and values up to 256
     * characters. Booleans become `true` / `false`, empty values are dropped.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, string>
     */
    public static function normalizeProperties(array $properties): array
    {
        $max = max(0, (int) config('alfakit-analytics.max_properties', 30));
        $clean = [];

        foreach ($properties as $key => $value) {
            if (count($clean) >= $max) {
                break;
            }

            $key = mb_substr(trim((string) $key), 0, 64);

            if ($key === '' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $value = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);

            if ($value !== '') {
                $clean[$key] = mb_substr($value, 0, 256);
            }
        }

        return $clean;
    }

    protected function findOpenVisit(Site $site, string $visitor, CarbonImmutable $occurredAt): ?Visit
    {
        $timeout = max(1, (int) config('alfakit-analytics.session_timeout', 30));

        return Visit::query()
            ->where('site_id', $site->getKey())
            ->where('visitor', $visitor)
            ->where('last_seen_at', '>=', $occurredAt->subMinutes($timeout))
            ->orderByDesc('last_seen_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    protected function storeProperties(Site $site, Event $event, array $properties, CarbonImmutable $local): void
    {
        $rows = [];

        foreach (self::normalizeProperties($properties) as $key => $value) {
            $rows[] = [
                'event_id' => $event->getKey(),
                'site_id' => $site->getKey(),
                'date' => $local->toDateString(),
                'event_name' => $event->name,
                'key' => $key,
                'value' => $value,
            ];
        }

        if ($rows !== []) {
            EventProperty::query()->insert($rows);
        }
    }

    protected function resolveCountry(Hit $hit): ?string
    {
        try {
            return HeaderCountryResolver::normalize($this->countries->resolve($hit->ip, $hit->request));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    protected function isExcludedPath(string $path): bool
    {
        foreach ((array) config('alfakit-analytics.exclude_paths', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && Sql::matchesPattern(PageUrl::normalizePath($pattern), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function botPatterns(): array
    {
        return array_values(array_filter((array) config('alfakit-analytics.bot_patterns', []), is_string(...)));
    }
}
