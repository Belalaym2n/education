<?php

namespace App\Contracts;

interface GameExamAttemptRepositoryInterface
{
    public function find(int $id): ?array;

    public function findForUpdate(int $id): ?array;

    public function findByExamAndStudent(int $examId, int $studentId): ?array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function insertAttemptQuestion(array $data): int;

    /** Attempt questions joined with question content, ordered by position. */
    public function attemptQuestions(int $attemptId): array;

    public function attemptQuestionForUpdate(int $attemptId, int $questionId): ?array;

    public function updateAttemptQuestion(int $id, array $data): int;

    /** Atomic counter update on the (already locked) attempt row; bumps sync_version. */
    public function incrementTotals(int $attemptId, int $earned, int $deducted, int $correct, int $failed): int;

    public function openQuestionCount(int $attemptId): int;

    /** Marks still-open questions with their final result (DEC-6) and returns the counts. */
    public function closeOpenQuestions(int $attemptId, string $nowUtc): void;

    /** @return array{correct:int, failed:int} */
    public function resultCounts(int $attemptId): array;

    /** @return array<int, array> choices grouped by question_id (incl. is_correct) */
    public function choicesForQuestions(array $questionIds): array;

    public function findChoice(int $questionId, int $choiceId): ?array;

    public function answerByAction(int $attemptId, string $clientActionId): ?array;

    public function insertAnswer(array $data): int;

    /** @return array<int, array> answers grouped by question_id, ordered by attempt_no */
    public function answersForAttempt(int $attemptId): array;

    /** Ids of in_progress attempts whose expires_at + grace is before $nowUtc. */
    public function expiredOpenAttemptIds(string $nowUtc, int $limit): array;

    /** @return array{rows: array, total: int} */
    public function results(int $examId, array $filters, int $limit, int $offset, string $sort, string $order): array;

    public function resultStatistics(int $examId, array $filters): array;

    public function questionDifficulty(int $examId): array;

    public function topStudents(string $fromUtc, string $toUtc, ?int $stageId, int $limit): array;

    /** Attempt joined with student/user/stage/exam for the dashboard detail view. */
    public function attemptDetail(int $attemptId): ?array;
}
