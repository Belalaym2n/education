<?php

namespace App\Contracts;

interface StudentResultRepositoryInterface
{
    public function find(int $id): ?array;

    public function findRaw(int $id): ?array;

    /**
     * @param array $filters see StudentResultRepository::buildWhere() for supported keys
     */
    public function paginate(array $filters, int $limit, int $offset, string $sortBy, string $sortDir): array;

    public function count(array $filters): int; 

    public function existsForStudentInYear(int $studentId, int $academicYearId, ?int $excludeId = null): bool;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function softDelete(int $id): int;
}
