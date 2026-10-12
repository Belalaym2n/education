<?php

namespace App\Support;

use App\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts a calendar month as the teacher sees it (Africa/Cairo) into the
 * UTC half-open range [from, to) used to query UTC timestamps (DEC-15).
 * Handles Egypt's DST automatically through the tz database.
 */
final class CairoMonth
{
    public const TIMEZONE = 'Africa/Cairo';

    /** @return array{from:string,to:string,label:string} DB-format UTC strings */
    public static function range(string $yearMonth): array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $yearMonth, $m)) {
            throw new ValidationException(['month' => 'month must be in YYYY-MM format']);
        }
        $tz = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable(sprintf('%s-%s-01 00:00:00', $m[1], $m[2]), $tz);
        $end = $start->modify('first day of next month');

        return [
            'from' => $start->setTimezone($utc)->format(Clock::DB_FORMAT),
            'to' => $end->setTimezone($utc)->format(Clock::DB_FORMAT),
            'label' => $yearMonth,
        ];
    }

    public static function current(Clock $clock): string
    {
        return $clock->now()->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m');
    }

    /** Day boundaries for `date_from` / `date_to` filters typed in Cairo time. */
    public static function dayStartUtc(string $date): string
    {
        return self::dayBoundary($date, '00:00:00', false);
    }

    public static function dayEndUtcExclusive(string $date): string
    {
        return self::dayBoundary($date, '00:00:00', true);
    }

    private static function dayBoundary(string $date, string $time, bool $nextDay): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationException(['date' => 'dates must be in YYYY-MM-DD format']);
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "$date $time", new DateTimeZone(self::TIMEZONE));
        if ($dt === false) {
            throw new ValidationException(['date' => 'invalid date']);
        }
        if ($nextDay) {
            $dt = $dt->modify('+1 day');
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format(Clock::DB_FORMAT);
    }
}
