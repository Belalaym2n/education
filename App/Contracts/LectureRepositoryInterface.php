<?php

namespace App\Contracts;

interface LectureRepositoryInterface
{
    /**
     * Find a single lecture by id, ignoring soft-deleted rows.
     */
    
    public function find(int $id): ?array;


    public function getNextOrderNumber(int $stageId): int;
    /**
     * Find a single lecture by id, including soft-deleted rows.
     * Used internally for slug-collision checks and admin recovery flows.
     */
    public function findWithTrashed(int $id): ?array;

    /**
     * Find a lecture by slug, ignoring soft-deleted rows.
     */
    public function findBySlug(string $slug): ?array;

    /**
     * Admin listing: all lectures (any stage/status), with pagination/sort/search/filters.
     *
     * @return array{data: array, total: int}
     */
    public function allForAdmin(array $filters, int $limit, int $offset, string $sort, string $order): array;

    /**
     * Student listing: only published lectures within the student's own stage.
     *
     * @return array{data: array, total: int}
     */
    public function allForStudent(int $stageId, array $filters, int $limit, int $offset, string $sort, string $order): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function softDelete(int $id): int;

    /**
     * Check if a slug already exists (including soft-deleted rows), optionally excluding one id.
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool;

    /**
     * Check if an order_number is already used within a stage, optionally excluding one id.
     */ 
    public function orderNumberExistsInStage(int $stageId, int $orderNumber, ?int $excludeId = null): bool;

    /**
     * Verify a stage_id exists in the stages table.
     */
    public function stageExists(int $stageId): bool;

    public function findByStageId(int $stageId): array;
} 