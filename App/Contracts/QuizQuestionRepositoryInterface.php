<?php

namespace App\Contracts;

interface QuizQuestionRepositoryInterface
{
    public function find(int $id): ?array;

    /** All questions for a quiz, in order, with their choices attached. */
    public function allForQuiz(int $quizId): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function softDelete(int $id): int;

    /** Replace all choices for a question (used by the question builder's bulk-save). */
    public function replaceChoices(int $questionId, array $choices): void;

    public function choicesForQuestion(int $questionId): array;

    /** Bulk delete all questions (and cascade choices via FK) for a quiz — used by "replace all" saves. */
    public function deleteAllForQuiz(int $quizId): void;
}
