<?php

namespace App\Support;

use App\Config\GameExamConstants as C;
use App\Exceptions\ValidationException;

/** Reads and clamps list/filter query-string values from $_GET. */
final class ListQuery
{
    public static function limit(array $q): int
    {
        $limit = isset($q['limit']) && is_numeric($q['limit']) ? (int) $q['limit'] : C::DEFAULT_PAGE_LIMIT;
        return max(1, min(C::MAX_PAGE_LIMIT, $limit));
    }

    public static function offset(array $q): int
    {
        return isset($q['offset']) && is_numeric($q['offset']) ? max(0, (int) $q['offset']) : 0;
    }

    public static function str(array $q, string $key, int $max = 100): ?string
    {
        if (!isset($q[$key]) || !is_string($q[$key])) {
            return null;
        }
        $v = trim($q[$key]);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public static function int(array $q, string $key): ?int
    {
        return isset($q[$key]) && is_numeric($q[$key]) ? (int) $q[$key] : null;
    }

    public static function boolOrNull(array $q, string $key): ?bool
    {
        if (!isset($q[$key]) || $q[$key] === '') {
            return null;
        }
        return in_array($q[$key], ['1', 'true', 1, true], true);
    }

    public static function oneOf(array $q, string $key, array $allowed, ?string $default = null): ?string
    {
        $v = self::str($q, $key);
        if ($v === null) {
            return $default;
        }
        if (!in_array($v, $allowed, true)) {
            throw new ValidationException([$key => "$key must be one of: " . implode(', ', $allowed)]);
        }
        return $v;
    }

    public static function order(array $q): string
    {
        return (self::str($q, 'order') ?? 'desc') === 'asc' ? 'asc' : 'desc';
    }

    /** Cairo-day date filters → UTC half-open range. */
    public static function dateRange(array $q): array
    {
        $from = self::str($q, 'date_from');
        $to = self::str($q, 'date_to');
        return [
            'from_utc' => $from ? CairoMonth::dayStartUtc($from) : null,
            'to_utc' => $to ? CairoMonth::dayEndUtcExclusive($to) : null,
        ];
    }

    public static function authUserId(): int
    {
        return (int) ($_SERVER['auth_user_id'] ?? 0);
    }
}
