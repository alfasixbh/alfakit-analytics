<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Throwable;

/**
 * A range of local dates in a site's timezone (both ends included), from a preset
 * (`today`, `7d`, `30d`, `month`…) or custom dates, and the period it is compared with.
 */
final readonly class Period
{
    public const TODAY = 'today';

    public const YESTERDAY = 'yesterday';

    public const LAST_7_DAYS = '7d';

    public const LAST_30_DAYS = '30d';

    public const THIS_MONTH = 'month';

    public const LAST_MONTH = 'last_month';

    public const THIS_YEAR = 'year';

    public const LAST_12_MONTHS = '12mo';

    public const CUSTOM = 'custom';

    /** @var array<int, string> */
    public const PRESETS = [self::TODAY, self::YESTERDAY, self::LAST_7_DAYS, self::LAST_30_DAYS, self::THIS_MONTH, self::LAST_MONTH, self::THIS_YEAR, self::LAST_12_MONTHS];

    public const DEFAULT = self::LAST_30_DAYS;

    /** Longest custom range, in days. */
    public const MAX_DAYS = 3660;

    public const HOUR = 'hour';

    public const DAY = 'day';

    public const MONTH = 'month';

    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
    ) {}

    public static function preset(string $preset, string $timezone, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();

        [$from, $to] = match ($preset) {
            self::TODAY => [$today, $today],
            self::YESTERDAY => [$today->subDay(), $today->subDay()],
            self::LAST_7_DAYS => [$today->subDays(6), $today],
            self::THIS_MONTH => [$today->startOfMonth(), $today],
            self::LAST_MONTH => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            self::THIS_YEAR => [$today->startOfYear(), $today],
            self::LAST_12_MONTHS => [$today->subMonthsNoOverflow(12)->addDay(), $today],
            default => [$today->subDays(29), $today],
        };

        return new self(in_array($preset, self::PRESETS, true) ? $preset : self::DEFAULT, $from, $to, $timezone);
    }

    /**
     * A custom range; null when the dates are not valid `Y-m-d` dates.
     */
    public static function custom(?string $from, ?string $to, string $timezone): ?self
    {
        $from = self::parseDate($from, $timezone);
        $to = self::parseDate($to, $timezone);

        if ($from === null || $to === null) {
            return null;
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        return new self(self::CUSTOM, $from, $to, $timezone);
    }

    /**
     * The period of a dashboard request (`period`, `from`, `to`), falling back to the
     * default preset for anything invalid.
     */
    public static function fromInput(mixed $period, mixed $from, mixed $to, string $timezone): self
    {
        if ($period === self::CUSTOM) {
            $custom = self::custom(is_string($from) ? $from : null, is_string($to) ? $to : null, $timezone);

            if ($custom !== null) {
                return $custom;
            }
        }

        return self::preset(is_string($period) ? $period : self::DEFAULT, $timezone);
    }

    private static function parseDate(?string $date, string $timezone): ?CarbonImmutable
    {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        } catch (Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    /**
     * The period of the same length right before this one (the same days of the previous
     * month, or year, for month and year presets).
     */
    public function previous(): self
    {
        [$from, $to] = match ($this->preset) {
            self::THIS_MONTH, self::LAST_MONTH => [$this->from->subMonthNoOverflow(), $this->preset === self::LAST_MONTH ? $this->from->subMonthNoOverflow()->endOfMonth()->startOfDay() : $this->to->subMonthNoOverflow()],
            self::THIS_YEAR => [$this->from->subYearNoOverflow(), $this->to->subYearNoOverflow()],
            default => [$this->from->subDays($this->days()), $this->to->subDays($this->days())],
        };

        return new self(self::CUSTOM, $from, $to, $this->timezone);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function isSingleDay(): bool
    {
        return $this->from->isSameDay($this->to);
    }

    /**
     * Whether today (in the site's timezone) is part of the period.
     */
    public function includesToday(): bool
    {
        $today = CarbonImmutable::now($this->timezone)->toDateString();

        return $this->fromDate() <= $today && $today <= $this->toDate();
    }

    /**
     * The chart's buckets: hours of a single day, months of long ranges, days otherwise.
     */
    public function interval(): string
    {
        return match (true) {
            $this->isSingleDay() => self::HOUR,
            $this->days() > 120 => self::MONTH,
            default => self::DAY,
        };
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * Every date of the period (`Y-m-d`).
     *
     * @return array<int, string>
     */
    public function dates(): array
    {
        $dates = [];

        foreach (CarbonPeriod::create($this->from, $this->to) as $date) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return $this->preset === self::CUSTOM
            ? ['period' => self::CUSTOM, 'from' => $this->fromDate(), 'to' => $this->toDate()]
            : ['period' => $this->preset];
    }
}
