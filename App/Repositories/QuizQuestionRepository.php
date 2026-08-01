<?php

namespace App\Repositories;

use App\Contracts\QuizQuestionRepositoryInterface;
use App\Database\Database;

class QuizQuestionRepository implements QuizQuestionRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM quiz_questions WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    public function allForQuiz(int $quizId): array
    {
        $questions = $this->db->query(
            "SELECT * FROM quiz_questions
             WHERE quiz_id = ? AND deleted_at IS NULL
             ORDER BY order_number ASC, id ASC",
            [$quizId]
        );

        if (empty($questions)) {
            return [];
        }

        $questionIds = array_column($questions, 'id');
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

        $allChoices = $this->db->query(
            "SELECT * FROM quiz_question_choices
             WHERE question_id IN ($placeholders)
             ORDER BY question_id ASC, order_number ASC",
            $questionIds
        );

        $choicesByQuestion = [];
        foreach ($allChoices as $choice) {
            $choicesByQuestion[$choice['question_id']][] = $choice;
        }

        foreach ($questions as &$question) {
            $question['choices'] = $choicesByQuestion[$question['id']] ?? [];
        }
        unset($question);

        return $questions;
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('quiz_questions', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('quiz_questions', $data, 'id = ?', [$id]);
    }

    public function softDelete(int $id): int
    {
        return $this->db->update('quiz_questions', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public function replaceChoices(int $questionId, array $choices): void
    {
        $this->db->delete('quiz_question_choices', 'question_id = ?', [$questionId]);

        foreach ($choices as $index => $choice) {
            $this->db->insert('quiz_question_choices', [
                'question_id' => $questionId,
                'choice_text' => $choice['choice_text'] ?? null,
                'image_url' => $choice['image_url'] ?? null,
                'is_correct' => !empty($choice['is_correct']) ? 1 : 0,
                'order_number' => $choice['order_number'] ?? $index,
            ]);
        }
    }

    public function choicesForQuestion(int $questionId): array
    {
        return $this->db->query(
            "SELECT * FROM quiz_question_choices WHERE question_id = ? ORDER BY order_number ASC",
            [$questionId]
        );
    }

    public function deleteAllForQuiz(int $quizId): void
    {
        // Choices cascade via FK (fk_choices_question ON DELETE CASCADE) once
        // the parent questions are hard-deleted; here we soft-delete
        // questions instead, so do it explicitly and keep choices intact
        // for the (soft-deleted) question's history/audit trail.
        $this->db->query(
            "UPDATE quiz_questions SET deleted_at = NOW() WHERE quiz_id = ? AND deleted_at IS NULL",
            [$quizId]
        );
    }
}
