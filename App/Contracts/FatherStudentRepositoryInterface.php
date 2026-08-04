<?php

namespace App\Contracts;

interface FatherStudentRepositoryInterface
{
    public function link(int $fatherId, int $studentId, string $relationshipType = 'father', bool $isPrimary = true): int;

    /**
     * All father/guardian user_ids linked to one student.
     *
     * @return int[]
     */
    public function findFatherUserIdsByStudentId(int $studentId): array;

    /**
     * All father/guardian user_ids linked to ANY of the given students, deduped.
     * One query regardless of how many student IDs are passed — used by the
     * bulk attendance flow to avoid N+1 lookups.
     *
     * @param int[] $studentIds
     * @return int[]
     */
    public function findFatherUserIdsByStudentIds(array $studentIds): array;

    /** 
     * Of the given student IDs, returns the subset that has at least one
     * linked, active father/guardian. Used to classify "Parent Not Found"
     * failures in bulk attendance without querying per-row.
     *
     * @param int[] $studentIds
     * @return int[]
     */ 
    public function findStudentIdsWithAnyFather(array $studentIds): array;

    /**
     * @return int[] student_id list
     */
    public function findStudentIdsByFatherUserId(int $fatherUserId): array;
} 