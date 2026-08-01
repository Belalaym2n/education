<?php

namespace App\Helpers;

class Pagination
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /**
     * Normalizes raw page/per_page query params into safe int values.
     */
    public static function resolve(array $query): array 
    {
        $page = isset($query['page']) ? (int) $query['page'] : 1;
        $perPage = isset($query['per_page']) ? (int) $query['per_page'] : self::DEFAULT_PER_PAGE;

        if ($page < 1) {
            $page = 1;
        }

        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE; 
        }

        $offset = ($page - 1) * $perPage;

        return [$page, $perPage, $offset];
    }

    /**
     * Builds the standard pagination metadata block returned alongside `data`.
     */
    public static function meta(int $total, int $page, int $perPage): array
    {
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ];
    }
}
 