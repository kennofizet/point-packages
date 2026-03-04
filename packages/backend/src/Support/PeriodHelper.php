<?php declare(strict_types=1);

namespace Company\Workpoint\Support;

use Carbon\Carbon;

/**
 * Period boundaries for workpoint rules and queries. Package-only; no core dependency.
 */
final class PeriodHelper
{
    public const PERIOD_DAY = 'day';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_YEAR = 'year';

    public const PERIODS_ALL = [self::PERIOD_DAY, self::PERIOD_WEEK, self::PERIOD_MONTH, self::PERIOD_YEAR];

    /**
     * Start of the given period (current day/week/month/year).
     */
    public static function start(string $period): Carbon
    {
        $now = Carbon::now();
        return match (strtolower($period)) {
            self::PERIOD_YEAR => $now->copy()->startOfYear(),
            self::PERIOD_MONTH => $now->copy()->startOfMonth(),
            self::PERIOD_WEEK => $now->copy()->startOfWeek(),
            default => $now->copy()->startOfDay(),
        };
    }

    /**
     * Start and end of the given period (end = now).
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public static function range(string $period): array
    {
        return [
            'start' => self::start($period),
            'end' => Carbon::now(),
        ];
    }

    public static function isValidPeriod(string $period): bool
    {
        return in_array(strtolower($period), self::PERIODS_ALL, true);
    }

    /**
     * Key for the current period (for storage/lookups in period_totals table).
     * day: Y-m-d, week: o-W (ISO), month: Y-m, year: Y.
     */
    public static function periodKey(string $period): string
    {
        $now = Carbon::now();
        return match (strtolower($period)) {
            self::PERIOD_YEAR => $now->format('Y'),
            self::PERIOD_MONTH => $now->format('Y-m'),
            self::PERIOD_WEEK => $now->format('o-\WW'),
            default => $now->format('Y-m-d'),
        };
    }
}
