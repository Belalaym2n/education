<?php

namespace App\Contracts;

interface QuizRepositoryInterface
{
    public function find(int $id): ?array;
    public function getStageQuizzes(int $stageId,array $filters, int $limit, int $offset, string $sort, string $order): ?array;

    public function findBySlug(string $slug): ?array;
  
    /**
     * Server-side filtered + paginated list for the Quiz Dashboard.
     * $filters keys (all optional): search, stage_id, status, has_essay,
     * has_mcq, created_by, date_from, date_to, availability
     * (upcoming|live|finished|draft|archived|hidden).
     */
    public function paginate(array $filters, int $limit, int $offset, string $sort, string $order): array;

    public function count(array $filters): int;

    /**
     * Aggregate counts/averages for the Dashboard Insights row — reacts to
     * the same $filters as paginate() but ignores limit/offset.
     */
    public function aggregateInsights(array $filters): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function softDelete(int $id): int;

    public function restore(int $id): int;

    /** Recompute total_marks/question_count/has_mcq/has_essay from quiz_questions. */
    public function recalculateTotals(int $quizId): void;

    public function countAttempts(int $quizId): int;

    public function slugExists(string $slug, ?int $excludeId = null): bool;
}
