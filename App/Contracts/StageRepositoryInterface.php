<?php

namespace App\Contracts;

/**
 * StageRepositoryInterface
 *
 * Contract for the Stage data-access layer. Follows the same shape as
 * StudentRepositoryInterface: the Service layer depends on this interface,
 * never on the concrete Repository, per Dependency Inversion.
 */
interface StageRepositoryInterface
{
    /** Active (non soft-deleted) stage by id. */
    public function find(int $id): ?array;

    /** Stage by id, optionally including soft-deleted rows (admin use). */
    public function findById(int $id, bool $withTrashed = false): ?array;
 
    /** Active stage by exact name. $excludeId lets update() ignore itself. */
    public function findByName(string $name, ?int $excludeId = null): ?array;

    /** All stages ordered by order_number. */
    public function all(bool $withTrashed = false): array;

        public function getYears(bool $withTrashed = false): array;

    /**  
     * Paginated listing with optional filters/sort.
     * $options keys: with_trashed, only_trashed, name, order_number, sort_by, sort_direction
     *
     * @return array{items: array, total: int, page: int, per_page: int, total_pages: int}
     */
    public function paginate(int $page, int $perPage, array $options = []): array;

    public function count(array $options = []): int;

    public function search(string $keyword, bool $withTrashed = false): array;

    public function sort(string $column, string $direction = 'ASC', bool $withTrashed = false): array;

    public function filter(array $criteria): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    /** Soft delete (sets deleted_at). */
    public function delete(int $id): int;

    /** Clears deleted_at. */
    public function restore(int $id): int;

    public function exists(int $id): bool;

    public function existsByName(string $name, ?int $excludeId = null): bool;

    public function existsByOrder(int $orderNumber, ?int $excludeId = null): bool;

    /** Count of students currently assigned to this stage. */
    public function studentsCount(int $stageId): int;

    /** Next available order_number (max + 1), for auto-generation. */
    public function nextOrderNumber(): int;
}