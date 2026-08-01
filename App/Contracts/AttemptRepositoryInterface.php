<?php

namespace App\Contracts;

interface AttemptRepositoryInterface
{
    public function find(int $id): ?array;

    /** Latest attempt for a student on a quiz. */
    public function findLatestByQuizAndStudent(int $quizId, int $studentId): ?array;

    /** All attempts for a student on a quiz, ordered by attempt_number. */
    public function allForQuizAndStudent(int $quizId, int $studentId): array;

    /**
     * Paginated list for the Results Dashboard — filters by quiz + review status +
     * visibility. Includes answer counts per student for the "Pending/Completed" view.
     */
    public function paginate(
        int $quizId,
        array $filters,
        int $limit,
        int $offset,
        string $sort,
        string $order
    ): array;

    public function count(int $quizId, array $filters): int;

    /** Aggregates for Results Dashboard Insights card (avg score, pass rate, etc). */
    public function aggregateInsights(int $quizId): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function softDelete(int $id): int;

    /**
     * Auto-grade MCQ/objective answers on a quiz — called at submit time.
     * Returns array of attempt_answer IDs updated + summary (correct count, etc).
     */
    public function autoGradeObjectiveAnswers(int $attemptId): array;

    /**
     * Bulk-update essay/manual answers' review status and score. Used when teacher
     * publishes a batch of reviewed answers.
     */
    public function publishReviewedAnswers(int $quizId, array $answerIds, int $reviewerId): int;

    /** Recompute attempt total_score, percentage, is_passed from answer-level scores. */
    public function recalculateAttemptScore(int $attemptId): void;

    /** Update student's denormalized rolling fields (average_score, total_quizzes, etc). */
    public function updateStudentStats(int $studentId): void;
}
