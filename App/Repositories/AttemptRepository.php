<?php

namespace App\Repositories;

use App\Contracts\AttemptRepositoryInterface;
use App\Database\Database;
use App\Models\QuestionType;

class AttemptRepository implements AttemptRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT
            qa.*,
            q.headline AS quiz_name,
            u.full_name AS student_name
         FROM quiz_attempts qa
         INNER JOIN quizzes q ON q.id = qa.quiz_id
         INNER JOIN students s ON s.id = qa.student_id
         INNER JOIN users u ON u.id = s.user_id
         WHERE qa.id = ? AND qa.deleted_at IS NULL",
            [$id]
        );
    }
    public function findLatestByQuizAndStudent(int $quizId, int $studentId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM quiz_attempts
             WHERE quiz_id = ? AND student_id = ? AND deleted_at IS NULL
             ORDER BY attempt_number DESC LIMIT 1",
            [$quizId, $studentId]
        );
    }
 
    public function allForQuizAndStudent(int $quizId, int $studentId): array
    {
        return $this->db->query(
            "SELECT * FROM quiz_attempts
             WHERE quiz_id = ? AND student_id = ? AND deleted_at IS NULL
             ORDER BY attempt_number ASC",
            [$quizId, $studentId]
        );
    }

    public function paginate(
        int $quizId,
        array $filters,
        int $limit,
        int $offset, 
        string $sort,
        string $order
    ): array {
        [$where, $params] = $this->buildWhere($quizId, $filters);
        $sort = $this->sanitizeSortColumn($sort);
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);

  $sql = "
    SELECT
        qa.id,
        qa.quiz_id,
        qa.student_id,
        qa.attempt_number,
        qa.status,
        qa.started_at,
        qa.finished_at,
        qa.duration_used_seconds,
        qa.mcq_score,
        qa.essay_score,
        qa.total_score,
        qa.percentage,
        qa.is_passed,
        qa.review_status,
        qa.result_visible_at,
        qa.essay_published_at,

        u.full_name AS student_name,

        (SELECT COUNT(*)
         FROM quiz_attempt_answers qaa
         WHERE qaa.attempt_id = qa.id
           AND qaa.reviewed_at IS NOT NULL
        ) AS reviewed_count,

        (SELECT COUNT(*)
         FROM quiz_attempt_answers qaa
         WHERE qaa.attempt_id = qa.id
           AND qaa.question_id IN (
               SELECT id
               FROM quiz_questions
               WHERE type = 'essay'
                 AND deleted_at IS NULL
           )
           AND qaa.reviewed_at IS NULL
        ) AS pending_essay_count

    FROM quiz_attempts qa

    INNER JOIN students s
        ON s.id = qa.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    WHERE {$where}

    ORDER BY {$sort} {$order}

    LIMIT {$limit} OFFSET {$offset}
";

        return $this->db->query($sql, $params);
    }

    public function count(int $quizId, array $filters): int
    {
        [$where, $params] = $this->buildWhere($quizId, $filters);
        $row = $this->db->selectOne("SELECT COUNT(*) AS total FROM quiz_attempts qa WHERE {$where}", $params);
        return (int) ($row['total'] ?? 0);
    }

    public function aggregateInsights(int $quizId): array
    {
        $row = $this->db->selectOne(
            "SELECT
                COUNT(*) AS total_attempts,
                COUNT(DISTINCT student_id) AS unique_students,
                SUM(CASE WHEN status IN ('submitted','auto_submitted') THEN 1 ELSE 0 END) AS completed_attempts,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN is_passed = 1 THEN 1 ELSE 0 END) AS passed_count,
                SUM(CASE WHEN is_passed = 0 THEN 1 ELSE 0 END) AS failed_count,
                AVG(percentage) AS average_percentage,
                AVG(total_score) AS average_score,
                MAX(total_score) AS highest_score,
                MIN(total_score) AS lowest_score,
                SUM(CASE WHEN review_status IN ('pending','partial') THEN 1 ELSE 0 END) AS pending_reviews,
                SUM(CASE WHEN review_status = 'completed' AND essay_published_at IS NULL THEN 1 ELSE 0 END) AS unpublished_reviews,
                AVG(duration_used_seconds) AS average_duration_seconds
             FROM quiz_attempts
             WHERE quiz_id = ? AND deleted_at IS NULL",
            [$quizId]
        );

        $totalAttempts = (int) ($row['total_attempts'] ?? 0);
        $completed = (int) ($row['completed_attempts'] ?? 0);
        $passed = (int) ($row['passed_count'] ?? 0);

        $completionRate = $totalAttempts > 0 ? round($completed / $totalAttempts * 100, 2) : 0.0;
        $passRate = $completed > 0 ? round($passed / $completed * 100, 2) : 0.0;

        return [
            'total_attempts' => $totalAttempts,
            'unique_students' => (int) ($row['unique_students'] ?? 0),
            'completed_attempts' => $completed,
            'in_progress_count' => (int) ($row['in_progress_count'] ?? 0),
            'passed_count' => $passed,
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'average_percentage' => round((float) ($row['average_percentage'] ?? 0), 2),
            'average_score' => round((float) ($row['average_score'] ?? 0), 2),
            'highest_score' => round((float) ($row['highest_score'] ?? 0), 2),
            'lowest_score' => round((float) ($row['lowest_score'] ?? 0), 2),
            'completion_rate' => $completionRate,
            'pass_rate' => $passRate,
            'pending_reviews' => (int) ($row['pending_reviews'] ?? 0),
            'unpublished_reviews' => (int) ($row['unpublished_reviews'] ?? 0),
            'average_duration_seconds' => round((float) ($row['average_duration_seconds'] ?? 0), 0),
        ];
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('quiz_attempts', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('quiz_attempts', $data, 'id = ?', [$id]);
    }

    public function softDelete(int $id): int
    {
        return $this->db->update('quiz_attempts', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    /**
     * Auto-grade all MCQ/objective answers on an attempt. Returns metadata about
     * the grading operation: which questions were auto-graded, how many correct, etc.
     * Called at submit time before the attempt status is marked 'submitted'.
     */
    public function autoGradeObjectiveAnswers(int $attemptId): array
    {
        $answers = $this->db->query(
            "SELECT qaa.*, qq.type, qq.marks, qq.negative_marks
         FROM quiz_attempt_answers qaa
         INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
         WHERE qaa.attempt_id = ? AND qq.deleted_at IS NULL
         ORDER BY qaa.id",
            [$attemptId]
        );

        if (empty($answers)) {
            return ['graded_count' => 0, 'correct_count' => 0, 'total_marks_awarded' => 0];
        }

        $questionIds = array_unique(array_map(fn($a) => (int) $a['question_id'], $answers));
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

        $correctRows = $this->db->query(
            "SELECT question_id, id FROM quiz_question_choices
         WHERE question_id IN ($placeholders) AND is_correct = 1
         ORDER BY id",
            $questionIds
        );

        $correctByQuestion = [];
        foreach ($correctRows as $row) {
            $correctByQuestion[(int) $row['question_id']][] = (int) $row['id'];
        }

        $graded = 0;
        $correct = 0;
        $totalAwarded = 0;

        foreach ($answers as $answer) {
            if (!QuestionType::isAutoGraded($answer['type'])) {
                continue;
            }

            $correctIds = $correctByQuestion[(int) $answer['question_id']] ?? [];
            $isCorrect = $this->gradeObjectiveAnswer($answer, $correctIds);
            $marksAwarded = $isCorrect ? (float) $answer['marks'] : 0.0;

            if (!$isCorrect && (float) ($answer['negative_marks'] ?? 0) > 0) {
                $marksAwarded -= (float) $answer['negative_marks'];
            }

            $this->db->update('quiz_attempt_answers', [
                'is_correct' => $isCorrect ? 1 : 0,
                'marks_awarded' => max(0, $marksAwarded),
            ], 'id = ?', [$answer['id']]);

            $graded++;
            if ($isCorrect) {
                $correct++;
            }
            $totalAwarded += max(0, $marksAwarded);
        }

        return [
            'graded_count' => $graded,
            'correct_count' => $correct,
            'total_marks_awarded' => $totalAwarded,
        ];
    }

    private function gradeObjectiveAnswer(array $answer, array $correctIds): bool
    {
        $type = $answer['type'];
        $selectedChoiceIds = !empty($answer['selected_choice_ids'])
            ? json_decode($answer['selected_choice_ids'], true)
            : [];

        if (!is_array($selectedChoiceIds)) {
            $selectedChoiceIds = [];
        }

        if ($type === 'mcq' || $type === 'true_false' || $type === 'image_choice') {
            return count($selectedChoiceIds) === 1 && in_array((int) $selectedChoiceIds[0], $correctIds, true);
        }

        if ($type === 'multi_select') {
            $selectedSet = array_map('intval', $selectedChoiceIds);
            sort($selectedSet);
            $correctSorted = $correctIds;
            sort($correctSorted);
            return $selectedSet === $correctSorted;
        }

        return false;
    }

    public function publishReviewedAnswers(int $quizId, array $answerIds, int $reviewerId): int
    {
        if (empty($answerIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($answerIds), '?'));

        // Ensure all answers belong to this quiz before updating.
        $count = $this->db->update(
            'quiz_attempt_answers',
            [
                'reviewed_at' => date('Y-m-d H:i:s'),
                'reviewed_by' => $reviewerId,
            ],
            "id IN ($placeholders) AND question_id IN (
                SELECT id FROM quiz_questions WHERE quiz_id = ?
            )",
            array_merge($answerIds, [$quizId])
        );

        // Mark the parent attempt as having at least one review done.
        $this->db->query(
            "UPDATE quiz_attempts SET review_status = 'partial'
             WHERE id IN (
                SELECT DISTINCT attempt_id FROM quiz_attempt_answers
                WHERE id IN ($placeholders)
            ) AND review_status = 'pending'",
            $answerIds
        );

        return $count;
    }

    public function recalculateAttemptScore(int $attemptId): void
    {
        $row = $this->db->selectOne(
            "SELECT
                SUM(CASE WHEN marks_awarded IS NOT NULL THEN marks_awarded ELSE 0 END) AS total,
                SUM(CASE WHEN qq.type NOT IN ('essay','audio','video') AND marks_awarded IS NOT NULL
                          THEN marks_awarded ELSE 0 END) AS mcq_score,
                SUM(CASE WHEN qq.type = 'essay' AND marks_awarded IS NOT NULL
                          THEN marks_awarded ELSE 0 END) AS essay_score
             FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             WHERE qaa.attempt_id = ?",
            [$attemptId]
        );

        $attempt = $this->find($attemptId);
        if (!$attempt) {
            return;
        }

        $quiz = $this->db->selectOne(
            "SELECT total_marks, passing_score FROM quizzes WHERE id = ?",
            [$attempt['quiz_id']]
        );

        $totalScore = (float) ($row['total'] ?? 0);
        $totalMarks = (float) ($quiz['total_marks'] ?? 1); // avoid div by 0
        $percentage = round(($totalScore / max($totalMarks, 1)) * 100, 2);
        $isPassed = (float) ($quiz['passing_score'] ?? 0) > 0
            ? ($percentage >= (float) $quiz['passing_score'])
            : true; // if no passing score set, mark as passed

        $this->db->update('quiz_attempts', [
            'mcq_score' => (float) ($row['mcq_score'] ?? 0),
            'essay_score' => (float) ($row['essay_score'] ?? 0),
            'total_score' => $totalScore,
            'percentage' => $percentage,
            'is_passed' => $isPassed ? 1 : 0,
        ], 'id = ?', [$attemptId]);
    }

    public function updateStudentStats(int $studentId): void
    {
        // Recompute the student's rolling stats from all their completed attempts.
        $row = $this->db->selectOne(
            "SELECT
                COUNT(DISTINCT quiz_id) AS total_quizzes,
                AVG(percentage) AS average_score,
                SUM(CASE WHEN is_passed = 1 THEN 1 ELSE 0 END) AS passed_quizzes
             FROM quiz_attempts
             WHERE student_id = ? AND status IN ('submitted','auto_submitted')
               AND deleted_at IS NULL",
            [$studentId]
        );

        $totalQuizzes = (int) ($row['total_quizzes'] ?? 0);
        $averageScore = round((float) ($row['average_score'] ?? 0), 2);
        $passedQuizzes = (int) ($row['passed_quizzes'] ?? 0);
        $totalDegrees = $totalQuizzes > 0 ? round(($passedQuizzes / $totalQuizzes) * 100, 0) : 0;

        $this->db->update('students', [
            'total_quizzes' => $totalQuizzes,
            'average_score' => $averageScore,
            'total_degrees' => $totalDegrees,
        ], 'id = ?', [$studentId]);
    }

   

    private function buildWhere(int $quizId, array $filters): array
    {
        $clauses = ['qa.quiz_id = ?', 'qa.deleted_at IS NULL'];
        $params = [$quizId];

        if (!empty($filters['student_id'])) {
            $clauses[] = 'qa.student_id = ?';
            $params[] = (int) $filters['student_id'];
        }

        if (!empty($filters['search'])) {
            $clauses[] = 's.full_name LIKE ? OR s.student_id_display LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $clauses[] = 'qa.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['review_status'])) {
            $clauses[] = 'qa.review_status = ?';
            $params[] = $filters['review_status'];
        }

        if (isset($filters['is_passed'])) {
            $clauses[] = 'qa.is_passed = ?';
            $params[] = $filters['is_passed'] ? 1 : 0;
        }

        if (!empty($filters['date_from'])) {
            $clauses[] = 'qa.finished_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'qa.finished_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function sanitizeSortColumn(string $sort): string
    {
        $allowed = [
            'finished_at' => 'qa.finished_at',
            'total_score' => 'qa.total_score',
            'percentage' => 'qa.percentage',
            'student_name' => 's.full_name',
            'created_at' => 'qa.created_at',
        ];

        return $allowed[$sort] ?? 'qa.finished_at';
    }
}
