<?php

namespace App\Contracts;

interface AttemptAnswerRepositoryInterface
{
    public function find(int $id): ?array;

    /** All answers for an attempt, with question details joined. */
    public function allForAttempt(int $attemptId): array;

    /**
     * Pending essay/manual review answers for a quiz — used to populate
     * the Submission Detail "Essays Awaiting Review" worklist.
     */
    public function pendingReviewForQuiz(int $quizId): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    /**
     * Bulk-update answers with review data (teacher_notes, reviewed_by, reviewed_at, marks_awarded).
     * Returns count updated.
     */
    public function bulkReview(array $updates): int;

    /** Soft-delete an answer (used when a teacher removes an essay from review). */
    public function softDelete(int $id): int;
}
