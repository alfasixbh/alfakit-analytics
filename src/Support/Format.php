<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use Illuminate\Support\Number;
use Throwable;

/**
 * Formats metrics on the server in the current locale (the panel's), so renderers only
 * display strings.
 */
final class Format
{
    public static function number(float|int|null $value, int $precision = 0): string
    {
        if ($value === null) {
            return '–';
        }

        try {
            $formatted = Number::format($value, maxPrecision: $precision, locale: app()->getLocale());

            if (is_string($formatted)) {
                return $formatted;
            }
        } catch (Throwable) {
            // No intl extension: plain formatting below.
        }

        return number_format((float) $value, $precision);
    }

    /**
     * `12345` → `12.3k` (compact numbers for cards).
     */
    public static function compact(float|int|null $value): string
    {
        if ($value === null) {
            return '–';
        }

        if (abs($value) < 10000) {
            return self::number($value);
        }

        try {
            $formatted = Number::abbreviate($value, maxPrecision: 1);

            if (is_string($formatted)) {
                return $formatted;
            }
        } catch (Throwable) {
            // Fall through.
        }

        return self::number($value);
    }

    public static function percent(float|int|null $value, int $precision = 1): string
    {
        if ($value === null) {
            return '–';
        }

        try {
            $formatted = Number::percentage($value, maxPrecision: $precision, locale: app()->getLocale());

            if (is_string($formatted)) {
                return $formatted;
            }
        } catch (Throwable) {
            // Fall through.
        }

        return round((float) $value, $precision).'%';
    }

    /**
     * A duration in seconds: `45s`, `3m 05s`, `1h 02m`.
     */
    public static function duration(float|int|null $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }

        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return match (true) {
            $hours > 0 => __('alfakit-analytics::analytics.duration.hours', ['hours' => self::number($hours), 'minutes' => str_pad((string) $minutes, 2, '0', STR_PAD_LEFT)]),
            $minutes > 0 => __('alfakit-analytics::analytics.duration.minutes', ['minutes' => self::number($minutes), 'seconds' => str_pad((string) $rest, 2, '0', STR_PAD_LEFT)]),
            default => __('alfakit-analytics::analytics.duration.seconds', ['seconds' => self::number($rest)]),
        };
    }

    /**
     * A signed change in percent: `+12%`, `−3.5%`.
     */
    public static function change(?float $change): ?string
    {
        if ($change === null) {
            return null;
        }

        return ($change > 0 ? '+' : ($change < 0 ? '−' : '')).self::percent(abs($change), abs($change) >= 10 ? 0 : 1);
    }

    /**
     * Format a metric by its kind.
     */
    public static function metric(string $metric, float|int|null $value): string
    {
        return match ($metric) {
            'bounce_rate', 'conversion_rate' => self::percent($value),
            'visit_duration' => self::duration($value),
            'views_per_visit' => self::number($value, 2),
            default => self::number($value),
        };
    }
}
