<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * UTC clock used by every time-sensitive rule (expiry, retry gates,
 * ledger timestamps). Injected so tests can freeze/advance time.
 *
 * We never rely on MySQL NOW(): the existing Database class does not pin
 * the session time zone, so PHP is the single authority for "now".
 */
class Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s';
    public const API_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function nowDb(): string
    {
        return $this->now()->format(self::DB_FORMAT);
    }

    public static function parseDb(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat(self::DB_FORMAT, $value, new DateTimeZone('UTC'));
        return $dt === false ? null : $dt;
    }

    /** Parses ISO-8601 from clients ("2026-10-04T10:00:00Z", "+03:00" offsets, ...). */
    public static function parseIso(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function toApi(?string $dbValue): ?string
    {
        $dt = self::parseDb($dbValue);
        return $dt?->format(self::API_FORMAT);
    }
}
