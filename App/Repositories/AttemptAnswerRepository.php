<?php

namespace App\Repositories;

use App\Contracts\AttemptAnswerRepositoryInterface;
use App\Database\Database;

class AttemptAnswerRepository implements AttemptAnswerRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT qaa.*, qq.type, qq.question_text, qq.marks, qq.explanation
             FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             WHERE qaa.id = ?",
            [$id]
        );
    }

    public function allForAttempt(int $attemptId): array
    {
        $answers = $this->db->query(
            "SELECT
                qaa.id, qaa.attempt_id, qaa.question_id, qaa.selected_choice_ids,
                qaa.answer_text, qaa.answer_image_url, qaa.is_correct, qaa.marks_awarded,
                qaa.teacher_notes, qaa.reviewed_by, qaa.reviewed_at,
                qq.type, qq.question_text, qq.marks, qq.order_number, qq.explanation,
                qq.image_url, qq.negative_marks, qq.shuffle_choices,
                u.full_name AS reviewed_by_name
             FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             LEFT JOIN users u ON u.id = qaa.reviewed_by
             WHERE qaa.attempt_id = ? AND qq.deleted_at IS NULL
             ORDER BY qq.order_number ASC, qaa.id ASC",
            [$attemptId]
        );

        // Fetch choices for choice-based questions.
        $questionIds = array_unique(array_column($answers, 'question_id'));
        if (!empty($questionIds)) {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $choices = $this->db->query(
                "SELECT * FROM quiz_question_choices WHERE question_id IN ($placeholders) ORDER BY order_number",
                $questionIds
            );

            $choicesByQuestion = [];
            foreach ($choices as $choice) {
                $choicesByQuestion[$choice['question_id']][] = $choice;
            }

            foreach ($answers as &$answer) {
                $answer['choices'] = $choicesByQuestion[$answer['question_id']] ?? [];
            }
            unset($answer);
        }

        return $answers;
    }

    public function pendingReviewForQuiz(int $quizId): array
    {
        return $this->db->query(
            "SELECT
                qaa.id, qaa.attempt_id, qaa.question_id, qaa.answer_text,
                qaa.answer_image_url, qaa.marks_awarded, qaa.reviewed_at,
                qq.question_text, qq.marks, qq.type, qq.order_number,
                qa.student_id, s.full_name AS student_name, s.student_id_display,
                q.headline AS quiz_name
             FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             INNER JOIN quiz_attempts qa ON qa.id = qaa.attempt_id
             INNER JOIN quizzes q ON q.id = qa.quiz_id
             INNER JOIN students s ON s.id = qa.student_id
             WHERE q.id = ? AND qq.type = 'essay' AND qaa.reviewed_at IS NULL
               AND qa.deleted_at IS NULL AND qq.deleted_at IS NULL
             ORDER BY qa.finished_at DESC, qaa.id ASC",
            [$quizId]
        );
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('quiz_attempt_answers', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('quiz_attempt_answers', $data, 'id = ?', [$id]);
    }

    public function bulkReview(array $updates): int
    {
        $count = 0;

        // $updates is array of [id => [marks_awarded => X, teacher_notes => Y, ...]]
        foreach ($updates as $answerId => $data) {
            $data['reviewed_at'] = $data['reviewed_at'] ?? date('Y-m-d H:i:s');
            $count += $this->db->update('quiz_attempt_answers', $data, 'id = ?', [$answerId]);
        }

        return $count;
    }

    public function softDelete(int $id): int
    {
        // Rarely used — typically you don't delete answers, just un-review them.
        // But if a teacher needs to mark an answer as "skip this one", this is the path.
        return $this->db->update('quiz_attempt_answers', ['reviewed_at' => null], 'id = ?', [$id]);
    }
}
