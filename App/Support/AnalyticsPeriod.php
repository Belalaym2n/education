<?php

namespace App\Support;

use App\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Resolves a dashboard date selection into the current window and the
 * immediately preceding equivalent window.
 *
 * All values are wall-clock strings in the DATABASE SESSION time zone
 * (the same clock MySQL uses for every `DEFAULT CURRENT_TIMESTAMP` column).
 * Arithmetic is done on naive wall-clock values (a UTC DateTimeZone object
 * is used only so PHP never applies DST shifts of its own).
 *
 * Windows are half-open: [from, to).
 *
 * Comparison rule: when the current window is still running (it contains
 * "now"), the previous window covers only the same elapsed length, so
 * month-to-date is compared with the same elapsed part of last month, never
 * with the full previous month.
 */
final class AnalyticsPeriod
{
    public const PRESETS = ['today', 'week', 'month', 'year', 'custom'];
    public const MAX_CUSTOM_DAYS = 1100;

    private const FMT = 'Y-m-d H:i:s';

    public function __construct(
        public string $preset,
        public string $from,
        public string $to,
        public string $prevFrom,
        public string $prevTo,
        public string $granularity,
        public bool $isPartial,
        public string $fullTo,
    ) {}

    /**
     * @param string $localNow DB-session "now" (Y-m-d H:i:s)
     */
    public static function resolve(string $preset, ?string $fromDate, ?string $toDate, string $localNow): self
    {
        if (!in_array($preset, self::PRESETS, true)) {
            throw new ValidationException(['period' => 'period must be one of: ' . implode(', ', self::PRESETS)]);
        }

        $now = self::parse($localNow, 'now');
        $today = $now->setTime(0, 0);

        switch ($preset) {
            case 'today':
                $start = $today;
                $fullEnd = $start->modify('+1 day');
                $prevStart = $start->modify('-1 day');
                break;
            case 'week':
                // Monday-based week, matching AttendanceRepository::statistics().
                $start = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
                $fullEnd = $start->modify('+7 days');
                $prevStart = $start->modify('-7 days');
                break;
            case 'month':
                $start = $today->modify('first day of this month');
                $fullEnd = $start->modify('first day of next month');
                $prevStart = $start->modify('first day of previous month');
                break;
            case 'year':
                $start = $today->setDate((int) $today->format('Y'), 1, 1);
                $fullEnd = $start->modify('+1 year');
                $prevStart = $start->modify('-1 year');
                break;
            default: // custom
                $start = self::parseDate($fromDate, 'from');
                $endDay = self::parseDate($toDate, 'to');
                if ($endDay < $start) {
                    throw new ValidationException(['to' => 'to must be on or after from']);
                }
                $fullEnd = $endDay->modify('+1 day');
                $days = (int) $start->diff($fullEnd)->days;
                if ($days > self::MAX_CUSTOM_DAYS) {
                    throw new ValidationException(['to' => 'date range cannot exceed ' . self::MAX_CUSTOM_DAYS . ' days']);
                }
                if ($start > $now) {
                    throw new ValidationException(['from' => 'from cannot be in the future']);
                }
                $prevStart = $start->modify("-$days days");
                break;
        }

        $isPartial = $now < $fullEnd;
        $end = $isPartial ? $now : $fullEnd;

        // Same elapsed length, never spilling into the current window.
        $elapsed = $end->getTimestamp() - $start->getTimestamp();
        $prevEnd = $prevStart->modify("+$elapsed seconds");
        if ($prevEnd > $start) {
            $prevEnd = $start;
        }

        return new self(
            $preset,
            $start->format(self::FMT),
            $end->format(self::FMT),
            $prevStart->format(self::FMT),
            $prevEnd->format(self::FMT),
            self::granularityFor($preset, $start, $fullEnd),
            $isPartial,
            $fullEnd->format(self::FMT),
        );
    }

    /**
     * Every bucket key of the current window, in order, so the series has
     * explicit zeros for buckets without records.
     *
     * @return string[]
     */
    public function bucketKeys(): array
    {
        $cursor = self::parse($this->from, 'from');
        $end = self::parse($this->fullTo, 'to');
        [$step, $fmt] = match ($this->granularity) {
            'hour' => ['+1 hour', 'Y-m-d H:00'],
            'day' => ['+1 day', 'Y-m-d'],
            default => ['first day of next month', 'Y-m'],
        };
        if ($this->granularity === 'month') {
            $cursor = $cursor->modify('first day of this month');
        }

        $keys = [];
        while ($cursor < $end && count($keys) < 400) {
            $keys[] = $cursor->format($fmt);
            $cursor = $cursor->modify($step);
        }
        return $keys;
    }

    /** SQL expression that turns a local DATETIME expression into this period's bucket key. */
    public function bucketSql(string $localExpr): string
    {
        return match ($this->granularity) {
            'hour' => "DATE_FORMAT($localExpr, '%Y-%m-%d %H:00')",
            'day' => "DATE_FORMAT($localExpr, '%Y-%m-%d')",
            default => "DATE_FORMAT($localExpr, '%Y-%m')",
        };
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'granularity' => $this->granularity,
            'is_partial' => $this->isPartial,
            'current' => ['from' => $this->from, 'to' => $this->to, 'full_to' => $this->fullTo],
            'previous' => ['from' => $this->prevFrom, 'to' => $this->prevTo],
        ];
    }

    private static function granularityFor(string $preset, DateTimeImmutable $start, DateTimeImmutable $fullEnd): string
    {
        if ($preset === 'today') {
            return 'hour';
        }
        if ($preset === 'year') {
            return 'month';
        }
        $days = (int) $start->diff($fullEnd)->days;
        if ($days <= 1) {
            return 'hour';
        }
        return $days <= 92 ? 'day' : 'month';
    }

    private static function parse(string $value, string $field): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat(self::FMT, $value, new DateTimeZone('UTC'));
        if ($dt === false) {
            throw new ValidationException([$field => 'invalid date-time']);
        }
        return $dt;
    }

    private static function parseDate(?string $value, string $field): DateTimeImmutable
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ValidationException([$field => "$field is required for a custom range (YYYY-MM-DD)"]);
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException([$field => "$field is not a valid date"]);
        }
        if ((int) $dt->format('Y') < 2000) {
            throw new ValidationException([$field => "$field is out of range"]);
        }
        return $dt;
    }
}
