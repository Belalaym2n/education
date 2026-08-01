<?php

namespace App\DTO;

/**
 * Uniform pagination envelope reused by every "list" endpoint in the API.
 */
final class PaginationResult
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public array $items;
 
    public int $total;
    public int $limit;
    public int $offset;

    public function __construct(
        array $items,
        int $total,
        int $limit,
        int $offset
    ) {
        $this->items = $items;
        $this->total = $total;
        $this->limit = $limit;
        $this->offset = $offset;
    }


    public function toArray(): array
    {
        $currentPage = $this->limit > 0 ? (int) floor($this->offset / $this->limit) + 1 : 1;
        $totalPages = $this->limit > 0 ? (int) ceil($this->total / $this->limit) : 1;

        return [
            'data' => $this->items,
            'pagination' => [
                'total' => $this->total,
                'limit' => $this->limit,
                'offset' => $this->offset,
                'current_page' => $currentPage,
                'total_pages' => max($totalPages, 1),
                'has_more' => ($this->offset + $this->limit) < $this->total,
            ],
        ];
    }
}