<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use Locale;
use Throwable;

/**
 * The dimensions top lists break visits down by, and the filters the dashboard accepts.
 *
 * Visit dimensions are columns of the visits table; `page` groups page views by path,
 * `event` custom events by name, `goal` the site's goals, and `prop:{event}:{key}` the
 * values of one property of one custom event.
 */
final class Dimension
{
    public const PAGE = 'page';

    public const ENTRY_PAGE = 'entry_page';

    public const EXIT_PAGE = 'exit_page';

    public const SOURCE = 'source';

    public const CHANNEL = 'channel';

    public const REFERRER = 'referrer';

    public const UTM_SOURCE = 'utm_source';

    public const UTM_MEDIUM = 'utm_medium';

    public const UTM_CAMPAIGN = 'utm_campaign';

    public const UTM_CONTENT = 'utm_content';

    public const UTM_TERM = 'utm_term';

    public const COUNTRY = 'country';

    public const DEVICE = 'device';

    public const BROWSER = 'browser';

    public const OS = 'os';

    public const EVENT = 'event';

    public const GOAL = 'goal';

    public const PROPERTY_PREFIX = 'prop:';

    /** The value standing for "none" (direct visits, unknown countries) in filters. */
    public const NONE = '(none)';

    /** @var array<int, string> */
    public const VISIT_DIMENSIONS = [
        self::ENTRY_PAGE, self::EXIT_PAGE, self::SOURCE, self::CHANNEL, self::REFERRER,
        self::UTM_SOURCE, self::UTM_MEDIUM, self::UTM_CAMPAIGN, self::UTM_CONTENT, self::UTM_TERM,
        self::COUNTRY, self::DEVICE, self::BROWSER, self::OS,
    ];

    /** Filters that match the events of a visit rather than a column of the visit. */
    public const EVENT_FILTERS = [self::PAGE, self::EVENT, self::GOAL];

    /**
     * Every dimension the dashboard can filter by.
     *
     * @return array<int, string>
     */
    public static function filterable(): array
    {
        return [self::PAGE, ...self::VISIT_DIMENSIONS, self::EVENT, self::GOAL];
    }

    /**
     * Every dimension rollups are written for (property dimensions are added per event).
     *
     * @return array<int, string>
     */
    public static function rolledUp(): array
    {
        return [self::PAGE, ...self::VISIT_DIMENSIONS, self::EVENT, self::GOAL];
    }

    public static function isVisitDimension(string $dimension): bool
    {
        return in_array($dimension, self::VISIT_DIMENSIONS, true);
    }

    public static function isProperty(string $dimension): bool
    {
        return self::parseProperty($dimension) !== null;
    }

    public static function property(string $event, string $key): string
    {
        return self::PROPERTY_PREFIX.$event.':'.$key;
    }

    /**
     * `prop:Signup:plan` → `['Signup', 'plan']`.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseProperty(string $dimension): ?array
    {
        if (! str_starts_with($dimension, self::PROPERTY_PREFIX)) {
            return null;
        }

        $rest = substr($dimension, strlen(self::PROPERTY_PREFIX));
        $separator = strrpos($rest, ':');

        if ($separator === false || $separator === 0 || $separator === strlen($rest) - 1) {
            return null;
        }

        return [substr($rest, 0, $separator), substr($rest, $separator + 1)];
    }

    public static function isValid(string $dimension): bool
    {
        return in_array($dimension, self::rolledUp(), true) || self::isProperty($dimension);
    }

    /**
     * The label of a dimension (`Source`, `Country`…).
     */
    public static function label(string $dimension): string
    {
        if (($property = self::parseProperty($dimension)) !== null) {
            return $property[1];
        }

        return (string) __('alfakit-analytics::analytics.dimensions.'.$dimension);
    }

    /**
     * The label of a value: country names, device types and channels are translated, an
     * empty value is "Direct / None", "Unknown"…
     */
    public static function valueLabel(string $dimension, ?string $value): string
    {
        if ($value === null || $value === '' || $value === self::NONE) {
            return (string) __(match ($dimension) {
                self::SOURCE, self::REFERRER => 'alfakit-analytics::analytics.values.direct',
                self::UTM_SOURCE, self::UTM_MEDIUM, self::UTM_CAMPAIGN, self::UTM_CONTENT, self::UTM_TERM => 'alfakit-analytics::analytics.values.none',
                default => 'alfakit-analytics::analytics.values.unknown',
            });
        }

        return match ($dimension) {
            self::COUNTRY => self::countryName($value),
            self::DEVICE => (string) __('alfakit-analytics::analytics.devices.'.$value),
            self::CHANNEL => (string) __('alfakit-analytics::analytics.channels.'.$value),
            default => $value,
        };
    }

    public static function countryName(string $code): string
    {
        try {
            $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, app()->getLocale()) : '';
        } catch (Throwable) {
            $name = '';
        }

        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }

    /**
     * A regional-indicator flag for a two-letter country code (decorative).
     */
    public static function countryFlag(string $code): ?string
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }
}
