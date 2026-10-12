<?php

namespace App\Support;

/**
 * Builds a safe search predicate. Uses the FULLTEXT index (boolean mode,
 * prefix match) when at least one term reaches InnoDB's default minimum
 * token size (3); otherwise falls back to an escaped LIKE so short Arabic
 * words like "حرب" or two-letter terms still match.
 */
final class SearchSql
{
    /**
     * @param string[] $fulltextColumns columns of an existing FULLTEXT index, e.g. ['e.headline','e.description']
     * @param string[] $likeColumns columns searched with LIKE
     * @return array{0:string,1:array} [sql, params]
     */
    public static function build(string $term, array $fulltextColumns, array $likeColumns): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['1=1', []];
        }

        $words = preg_split('/\s+/u', preg_replace('/[+\-<>()~*"@]+/u', ' ', $term)) ?: [];
        $long = array_values(array_filter($words, fn($w) => mb_strlen($w, 'UTF-8') >= 3));

        if ($fulltextColumns !== [] && $long !== [] && count($long) === count(array_filter($words, fn($w) => $w !== ''))) {
            $against = implode(' ', array_map(fn($w) => '+' . $w . '*', $long));
            return ['MATCH(' . implode(', ', $fulltextColumns) . ') AGAINST (? IN BOOLEAN MODE)', [$against]];
        }

        $like = '%' . self::escapeLike($term) . '%';
        $parts = array_map(fn($c) => "$c LIKE ?", $likeColumns);
        return ['(' . implode(' OR ', $parts) . ')', array_fill(0, count($likeColumns), $like)];
    }

    public static function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** Whitelists sort columns; returns a safe "ORDER BY" fragment. */
    public static function orderBy(string $sort, string $order, array $allowed, string $default): string
    {
        $column = $allowed[$sort] ?? $allowed[$default];
        $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        return "$column $direction";
    }
}
