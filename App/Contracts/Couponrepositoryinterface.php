<?php

namespace App\Contracts;

/**
 * PATCHED VERSION — replace the whole interface with this one. Existing
 * method signatures for find/findForUpdate/findByCode(ForUpdate)/
 * findActiveByCodeAndLecture/codeExists/create/update/incrementTimesUsed/
 * softDelete/restore are unchanged. Only paginate()/count() gained trailing
 * optional params (backward compatible with existing call sites), and four
 * new methods were added.
 */
interface CouponRepositoryInterface
{  
    public function find(int $id): ?array;

    public function findForUpdate(int $id): ?array;

    public function findByCode(string $code): ?array;

    public function findByCodeForUpdate(string $code): ?array;

    public function findActiveByCodeAndLecture(string $code, int $lectureId): ?array;

    public function codeExists(string $code): bool;

    public function paginate(
        int $limit,
        int $offset,
        ?string $search = null,
        ?int $lectureId = null,
        ?bool $isActive = null,
        string $sortBy = 'created_at',
        string $sortDir = 'DESC',
        ?int $stageId = null,
        ?string $status = null
    ): array;

    public function count(
        ?string $search = null,
        ?int $lectureId = null,
        ?bool $isActive = null,
        ?int $stageId = null,
        ?string $status = null
    ): int;

    /** New — powers GET /coupons/summary. */
    public function summary(?string $search = null, ?int $lectureId = null, ?int $stageId = null): array;

    /** New — powers GET /coupons/export. */
    public function exportAll(
        ?string $search = null,
        ?int $lectureId = null,
        ?bool $isActive = null,
        ?int $stageId = null,
        ?string $status = null,
        string $sortBy = 'created_at',
        string $sortDir = 'DESC'
    ): array;

    public function create(array $data): int;

    /** New — powers POST /coupons/bulk. Returns inserted ids in order. */
    public function createMany(array $rows): array;

    public function update(int $id, array $data): int;

    public function incrementTimesUsed(int $id): int;

    public function softDelete(int $id): int;

    public function restore(int $id): int;
}