<?php

namespace App\Contracts;

interface PointsLedgerRepositoryInterface
{
    /** Inserts a ledger row; returns false if (source_type, source_id) already exists. */
    public function add(int $studentId, int $stageId, int $points, string $sourceType, int $sourceId, ?int $examId, string $createdAtUtc): bool;

    /** Applies a delta to students.points (optionally floored) and returns rows affected. */
    public function applyToStudent(int $studentId, int $delta, ?int $floor): int;

    public function studentPoints(int $studentId): int;

    /** Rows where students.points != SUM(ledger) — used by the repair script. */
    public function drift(): array;

    public function recalculate(int $studentId): int;
}
