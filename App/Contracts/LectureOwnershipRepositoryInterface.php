<?php

namespace App\Contracts;

interface  LectureOwnershipRepositoryInterface
{
    public function find(int $id): ?array;

    public function findByStudentAndLecture(int $studentId, int $lectureId): ?array;

    /** Locks the row if it exists (SELECT ... FOR UPDATE). Must be called inside a transaction. */
    public function findByStudentAndLectureForUpdate(int $studentId, int $lectureId): ?array;
 
    public function ownsLecture(int $studentId, int $lectureId): bool;
public function incrementViewCount(
    int $studentId,
    int $lectureId,
    int $maxViews 
): bool;

public function create(array $data): int;

    public function paginate(
        int $studentId,
        int $limit,
        int $offset, 
        ?string $search = null,
        ?int $stageId = null,
        string $sortBy = 'purchased_at',
        string $sortDir = 'DESC'
    ): array;

    
    public function count(int $studentId, ?string $search = null, ?int $stageId = null): int;
} 
