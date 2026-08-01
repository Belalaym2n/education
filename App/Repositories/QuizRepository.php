<?php

namespace App\Repositories;

use App\Contracts\QuizRepositoryInterface;
use App\Database\Database;

class QuizRepository implements QuizRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT q.*, st.name AS stage_name, u.full_name AS created_by_name
             FROM quizzes q
             INNER JOIN stages st ON st.id = q.stage_id
             LEFT JOIN users u ON u.id = q.created_by
             WHERE q.id = ? AND q.deleted_at IS NULL",
            [$id]
        );
    }

    public function getStageQuizzes(int $stageId,array $filters, int $limit, int $offset, string $sort, string $order): ?array
    {
   [$where, $params] = $this->buildWhere($filters);
        $sort = $this->sanitizeSortColumn($sort);
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min(200, (int) $limit));
        $offset = max(0, (int) $offset);
        $sql = "
        SELECT
            q.id,
            q.stage_id,
            q.headline,
            q.slug,
            q.description,
            q.status,
            q.is_visible,
            q.start_date,
            q.end_date,
            q.start_time,
            q.end_time,
            q.duration_seconds,
            q.show_result_immediately,
            q.passing_score,
            q.total_marks,
            q.question_count,
            q.has_mcq,
            q.has_essay,
            q.allow_retry,
            q.max_attempts,
            q.created_at,
            q.updated_at,

            st.name AS stage_name,

            (
                SELECT COUNT(*)
                FROM quiz_questions qq
                WHERE qq.quiz_id = q.id
                AND qq.deleted_at IS NULL
            ) AS questions_count

        FROM quizzes q
        INNER JOIN stages st
            ON st.id = q.stage_id

        WHERE q.stage_id = ?
          AND q.deleted_at IS NULL
          AND q.status = 'published'
          AND q.is_visible = 1

        ORDER BY
            q.start_date DESC,
            q.start_time DESC,
            q.created_at DESC
    ";

        return $this->db->query($sql, [$stageId]);
    }


 

    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM quizzes WHERE slug = ? AND deleted_at IS NULL",
            [$slug]
        );
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->selectOne(
                "SELECT id FROM quizzes WHERE slug = ? AND id != ? AND deleted_at IS NULL",
                [$slug, $excludeId]
            );
        } else {
            $row = $this->db->selectOne(
                "SELECT id FROM quizzes WHERE slug = ? AND deleted_at IS NULL",
                [$slug]
            );
        }

        return $row !== null;
    }

    public function paginate(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sort = $this->sanitizeSortColumn($sort);
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min(200, (int) $limit));
        $offset = max(0, (int) $offset);

        $sql = "
            SELECT
                q.id, q.stage_id, q.headline, q.slug, q.status, q.is_visible,
                q.start_date, q.end_date, q.start_time, q.end_time,
                q.duration_seconds, q.show_result_immediately, q.passing_score,
                q.total_marks, q.question_count, q.has_mcq, q.has_essay,
                q.allow_retry, q.max_attempts, q.created_by, q.created_at, q.updated_at,
                st.name AS stage_name,
                u.full_name AS created_by_name,
                (SELECT COUNT(*) FROM quiz_attempts qa
                    WHERE qa.quiz_id = q.id AND qa.deleted_at IS NULL) AS attempts_count,
                (SELECT COUNT(DISTINCT qa.student_id) FROM quiz_attempts qa
                    WHERE qa.quiz_id = q.id AND qa.deleted_at IS NULL) AS students_count,
                (SELECT COUNT(*) FROM quiz_attempts qa
                    WHERE qa.quiz_id = q.id AND qa.deleted_at IS NULL
                    AND qa.review_status IN ('pending','partial')) AS pending_reviews_count
            FROM quizzes q
            INNER JOIN stages st ON st.id = q.stage_id
            LEFT JOIN users u ON u.id = q.created_by
            WHERE {$where}
            ORDER BY {$sort} {$order}
            LIMIT {$limit} OFFSET {$offset}
        ";

        return $this->db->query($sql, $params);
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total FROM quizzes q WHERE {$where}",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    public function aggregateInsights(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        // Single pass over quizzes for status/type breakdown — cheap because
        // it reuses the same indexed WHERE clause as the list query.
        $statusRow = $this->db->selectOne(
            "SELECT
                COUNT(*) AS total_quizzes,
                SUM(CASE WHEN q.status = 'published' THEN 1 ELSE 0 END) AS published_count,
                SUM(CASE WHEN q.status = 'draft' THEN 1 ELSE 0 END) AS draft_count,
                SUM(CASE WHEN q.status = 'archived' THEN 1 ELSE 0 END) AS archived_count,
                SUM(CASE WHEN q.status = 'published' AND q.start_date <= CURDATE()
                          AND q.end_date >= CURDATE() THEN 1 ELSE 0 END) AS live_count,
                SUM(CASE WHEN q.status = 'published' AND q.start_date > CURDATE() THEN 1 ELSE 0 END) AS upcoming_count,
                SUM(CASE WHEN q.status = 'published' AND q.end_date < CURDATE() THEN 1 ELSE 0 END) AS finished_count,
                SUM(CASE WHEN q.has_essay = 1 THEN 1 ELSE 0 END) AS has_essay_count,
                SUM(CASE WHEN q.has_mcq = 1 THEN 1 ELSE 0 END) AS has_mcq_count,
                AVG(q.duration_seconds) AS average_duration_seconds
             FROM quizzes q
             WHERE {$where}",
            $params
        );

        // Attempt-level aggregates need their own scoped query (join, not filtered
        // by the quiz WHERE columns directly) — kept separate so the quiz-only
        // aggregate above stays index-friendly and isn't dragged into a join scan.
        $quizIdsSql = "SELECT q.id FROM quizzes q WHERE {$where}";
        $attemptsRow = $this->db->selectOne(
            "SELECT
                COUNT(*) AS total_attempts,
                COUNT(DISTINCT qa.student_id) AS total_students,
                AVG(qa.percentage) AS average_score,
                SUM(CASE WHEN qa.is_passed = 1 THEN 1 ELSE 0 END) AS passed_count,
                SUM(CASE WHEN qa.is_passed = 0 THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN qa.review_status IN ('pending','partial') THEN 1 ELSE 0 END) AS pending_reviews,
                AVG(qa.duration_used_seconds) AS average_completion_seconds
             FROM quiz_attempts qa
             WHERE qa.deleted_at IS NULL
               AND qa.quiz_id IN ({$quizIdsSql})",
            $params
        );

        $totalAttempts = (int) ($attemptsRow['total_attempts'] ?? 0);
        $passed = (int) ($attemptsRow['passed_count'] ?? 0);
        $completionRate = $totalAttempts > 0
            ? round(($passed + (int) ($attemptsRow['failed_count'] ?? 0)) / max($totalAttempts, 1) * 100, 2)
            : 0.0;
        $successRate = $totalAttempts > 0 ? round($passed / $totalAttempts * 100, 2) : 0.0;

        return [
            'total_quizzes' => (int) ($statusRow['total_quizzes'] ?? 0),
            'published_quizzes' => (int) ($statusRow['published_count'] ?? 0),
            'draft_quizzes' => (int) ($statusRow['draft_count'] ?? 0),
            'archived_quizzes' => (int) ($statusRow['archived_count'] ?? 0),
            'upcoming_quizzes' => (int) ($statusRow['upcoming_count'] ?? 0),
            'live_quizzes' => (int) ($statusRow['live_count'] ?? 0),
            'finished_quizzes' => (int) ($statusRow['finished_count'] ?? 0),
            'quizzes_with_essay' => (int) ($statusRow['has_essay_count'] ?? 0),
            'quizzes_with_mcq' => (int) ($statusRow['has_mcq_count'] ?? 0),
            'average_duration_seconds' => round((float) ($statusRow['average_duration_seconds'] ?? 0), 0),
            'total_attempts' => $totalAttempts,
            'total_students' => (int) ($attemptsRow['total_students'] ?? 0),
            'average_score' => round((float) ($attemptsRow['average_score'] ?? 0), 2),
            'pass_rate' => $successRate,
            'success_rate' => $successRate,
            'completion_rate' => $completionRate,
            'essay_pending_reviews' => (int) ($attemptsRow['pending_reviews'] ?? 0),
            'average_completion_seconds' => round((float) ($attemptsRow['average_completion_seconds'] ?? 0), 0),
        ];
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('quizzes', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('quizzes', $data, 'id = ?', [$id]);
    }

    public function softDelete(int $id): int
    {
        return $this->db->update('quizzes', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public function restore(int $id): int
    {
        return $this->db->update('quizzes', ['deleted_at' => null], 'id = ?', [$id]);
    }

    public function recalculateTotals(int $quizId): void
    {
        $row = $this->db->selectOne(
            "SELECT
                COALESCE(SUM(marks), 0) AS total_marks,
                COUNT(*) AS question_count,
                SUM(CASE WHEN type = 'essay' THEN 1 ELSE 0 END) AS essay_count,
                SUM(CASE WHEN type IN ('mcq','true_false','multi_select','image_choice') THEN 1 ELSE 0 END) AS mcq_count
             FROM quiz_questions
             WHERE quiz_id = ? AND deleted_at IS NULL",
            [$quizId]
        );

        $this->db->update('quizzes', [
            'total_marks' => $row['total_marks'] ?? 0,
            'question_count' => $row['question_count'] ?? 0,
            'has_essay' => ((int) ($row['essay_count'] ?? 0)) > 0 ? 1 : 0,
            'has_mcq' => ((int) ($row['mcq_count'] ?? 0)) > 0 ? 1 : 0,
        ], 'id = ?', [$quizId]);
    }

    public function countAttempts(int $quizId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total FROM quiz_attempts WHERE quiz_id = ? AND deleted_at IS NULL",
            [$quizId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Builds a parameterized WHERE clause from dashboard filters. Every
     * value is bound — never interpolated — even though the caller is
     * trusted, because this method is the one place filter handling lives
     * and a future caller might not be.
     *
     * @return array{0:string,1:array}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = ['q.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['search'])) {
            $clauses[] = '(MATCH(q.headline, q.description) AGAINST (? IN BOOLEAN MODE) OR q.headline LIKE ?)';
            $params[] = $filters['search'] . '*';
            $params[] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['stage_id'])) {
            $clauses[] = 'q.stage_id = ?';
            $params[] = (int) $filters['stage_id'];
        }

        if (!empty($filters['status'])) {
            $clauses[] = 'q.status = ?';
            $params[] = $filters['status'];
        }

        if (isset($filters['has_essay'])) {
            $clauses[] = 'q.has_essay = ?';
            $params[] = $filters['has_essay'] ? 1 : 0;
        }

        if (isset($filters['has_mcq'])) {
            $clauses[] = 'q.has_mcq = ?';
            $params[] = $filters['has_mcq'] ? 1 : 0;
        }

        if (!empty($filters['created_by'])) {
            $clauses[] = 'q.created_by = ?';
            $params[] = (int) $filters['created_by'];
        }

        if (!empty($filters['date_from'])) {
            $clauses[] = 'q.start_date >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'q.end_date <= ?';
            $params[] = $filters['date_to'];
        }

        // Availability is a computed state, but each state maps to a clean
        // indexed date/status comparison, so it's still pushed to SQL rather
        // than filtered in PHP after fetching everything.
        if (!empty($filters['availability'])) {
            switch ($filters['availability']) {
                case 'draft':
                    $clauses[] = "q.status = 'draft'";
                    break;
                case 'archived':
                    $clauses[] = "q.status = 'archived'";
                    break;
                case 'hidden':
                    $clauses[] = "q.status = 'published' AND q.is_visible = 0";
                    break;
                case 'upcoming':
                    $clauses[] = "q.status = 'published' AND q.is_visible = 1 AND q.start_date > CURDATE()";
                    break;
                case 'live':
                    $clauses[] = "q.status = 'published' AND q.is_visible = 1 AND q.start_date <= CURDATE() AND q.end_date >= CURDATE()";
                    break;
                case 'finished':
                    $clauses[] = "q.status = 'published' AND q.end_date < CURDATE()";
                    break;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function sanitizeSortColumn(string $sort): string
    {
        $allowed = [
            'created_at' => 'q.created_at',
            'headline' => 'q.headline',
            'start_date' => 'q.start_date',
            'end_date' => 'q.end_date',
            'question_count' => 'q.question_count',
            'total_marks' => 'q.total_marks',
        ];

        return $allowed[$sort] ?? 'q.created_at';
    }
}
