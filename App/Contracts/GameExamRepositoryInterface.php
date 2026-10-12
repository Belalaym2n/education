<?php

namespace App\Contracts;

interface GameExamRepositoryInterface
{
    public function find(int $id, bool $withDeleted = false): ?array;

    public function findForUpdate(int $id): ?array;

    /** @return array{rows: array, total: int} */
    public function list(array $filters, int $limit, int $offset, string $sort, string $order): array;

    public function statistics(array $filters, string $recentSinceUtc): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function softDelete(int $id, string $now, int $userId): int;

    public function restore(int $id, int $userId): int;

    /** Active questions ordered by order_number, each with a `choices` array (incl. is_correct). */
    public function questions(int $examId): array;

    public function questionIds(int $examId): array;

    public function insertQuestion(int $examId, array $question, int $order): int;

    public function updateQuestion(int $examId, int $questionId, array $question, int $order): int;

    public function softDeleteQuestionsExcept(int $examId, array $keepIds, string $now): int;

    public function replaceChoices(int $questionId, array $choices): void;

    public function refreshCounters(int $examId): void;

    public function updateQuestionOrder(int $examId, array $orderedIds): void;

    public function incrementAttempts(int $examId): void;

    public function stageExists(int $stageId): bool;

    /** @return array{rows: array, total: int} */
    public function listForStudent(int $studentId, int $stageId, array $filters, string $nowUtc, int $limit, int $offset): array;

    /** Exam visible to the student now (DEC-13), or one they already attempted. */
    public function findVisibleForStudent(int $examId, int $studentId, int $stageId, string $nowUtc): ?array;
}
