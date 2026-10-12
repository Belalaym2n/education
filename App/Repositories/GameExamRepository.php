<?php

namespace App\Repositories;

use App\Config\GameExamConstants as C;
use App\Contracts\GameExamRepositoryInterface;
use App\Database\Database;
use App\Support\SearchSql;

class GameExamRepository implements GameExamRepositoryInterface
{
    private Database $db;

    private const SORTS = [
        'created_at' => 'e.created_at',
        'updated_at' => 'e.updated_at',
        'published_at' => 'e.published_at',
        'headline' => 'e.headline',
        'question_count' => 'e.question_count',
        'max_points' => 'e.max_points',
        'attempts_count' => 'e.attempts_count',
    ];

    private const QUESTION_COLUMNS = [
        'type', 'question_text', 'score', 'second_attempt_score', 'negative_score', 'retry_seconds',
        'character_name', 'character_description', 'character_image_url',
        'front_text', 'front_image_url', 'back_text', 'back_image_url', 'info_text',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id, bool $withDeleted = false): ?array
    {
        $deleted = $withDeleted ? '' : 'AND e.deleted_at IS NULL';
        return $this->db->selectOne(
            "SELECT e.*, st.name AS stage_name, u.full_name AS created_by_name
             FROM game_exams e
             LEFT JOIN stages st ON st.id = e.stage_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.id = ? $deleted",
            [$id]
        );
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->db->selectOne("SELECT * FROM game_exams WHERE id = ? FOR UPDATE", [$id]);
    }

    public function list(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$where, $params] = $this->adminWhere($filters, true);
        $orderBy = SearchSql::orderBy($sort, $order, self::SORTS, 'created_at');
        $limit = (int) $limit;
        $offset = (int) $offset;

        $rows = $this->db->query(
            "SELECT e.*, st.name AS stage_name, u.full_name AS created_by_name
             FROM game_exams e
             LEFT JOIN stages st ON st.id = e.stage_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE $where
             ORDER BY $orderBy, e.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        $total = $this->db->selectOne("SELECT COUNT(*) AS c FROM game_exams e WHERE $where", $params);

        return ['rows' => $rows, 'total' => (int) ($total['c'] ?? 0)];
    }

    public function statistics(array $filters, string $recentSinceUtc): array
    {
        // Status/deleted filters are intentionally ignored here: the cards
        // ARE the status breakdown. Stage, dates, search and creator apply.
        [$where, $params] = $this->adminWhere($filters, false);

        $row = $this->db->selectOne(
            "SELECT
                COALESCE(SUM(e.deleted_at IS NULL), 0) AS total,
                COALESCE(SUM(e.deleted_at IS NULL AND e.status = 'published'), 0) AS published,
                COALESCE(SUM(e.deleted_at IS NULL AND e.status = 'draft'), 0) AS drafts,
                COALESCE(SUM(e.deleted_at IS NULL AND e.status = 'archived'), 0) AS archived,
                COALESCE(SUM(e.deleted_at IS NOT NULL), 0) AS deleted,
                COALESCE(SUM(e.deleted_at IS NULL AND e.created_at >= ?), 0) AS recent,
                COALESCE(SUM(CASE WHEN e.deleted_at IS NULL THEN e.question_count END), 0) AS total_questions,
                COALESCE(SUM(CASE WHEN e.deleted_at IS NULL THEN e.attempts_count END), 0) AS total_attempts
             FROM game_exams e
             WHERE $where",
            array_merge([$recentSinceUtc], $params)
        );

        return $row ?? [];
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('game_exams', $data);
    }
     public function update(int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }
        return $this->db->update('game_exams', $data,'id = ?', [$id]);
    }

    public function softDelete(int $id, string $now, int $userId): int
    {
        return $this->db->execute(
            "UPDATE game_exams SET deleted_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL",
            [$now, $userId, $id]
        );
    }

    public function restore(int $id, int $userId): int
    {
        return $this->db->execute(
            "UPDATE game_exams SET deleted_at = NULL, updated_by = ? WHERE id = ? AND deleted_at IS NOT NULL",
            [$userId, $id]
        );
    }

    public function questions(int $examId): array
    {
        $questions = $this->db->query(
            "SELECT * FROM game_exam_questions
             WHERE exam_id = ? AND deleted_at IS NULL
             ORDER BY order_number ASC, id ASC",
            [$examId]
        );
        if ($questions === []) {
            return [];
        }

        $ids = array_map(fn($q) => (int) $q['id'], $questions);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $choices = $this->db->query(
            "SELECT * FROM game_exam_question_choices
             WHERE question_id IN ($placeholders)
             ORDER BY order_number ASC, id ASC",
            $ids
        );

        $byQuestion = [];
        foreach ($choices as $c) {
            $byQuestion[(int) $c['question_id']][] = $c;
        }
        foreach ($questions as &$q) {
            $q['choices'] = $byQuestion[(int) $q['id']] ?? [];
        }
        unset($q);

        return $questions;
    }

    public function questionIds(int $examId): array
    {
        $rows = $this->db->query(
            "SELECT id FROM game_exam_questions WHERE exam_id = ? AND deleted_at IS NULL ORDER BY order_number, id",
            [$examId]
        );
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    public function insertQuestion(int $examId, array $question, int $order): int
    {
        $data = ['exam_id' => $examId, 'order_number' => $order];
        foreach (self::QUESTION_COLUMNS as $col) {
            $data[$col] = $question[$col] ?? null;
        }
        return (int) $this->db->insert('game_exam_questions', $data);
    }

    public function updateQuestion(int $examId, int $questionId, array $question, int $order): int
    {
        $set = ['order_number = ?'];
        $params = [$order];
        foreach (self::QUESTION_COLUMNS as $col) {
            $set[] = "$col = ?";
            $params[] = $question[$col] ?? null;
        }
        $params[] = $questionId;
        $params[] = $examId;

        return $this->db->execute(
            "UPDATE game_exam_questions SET " . implode(', ', $set) . "
             WHERE id = ? AND exam_id = ? AND deleted_at IS NULL",
            $params
        );
    }

    public function softDeleteQuestionsExcept(int $examId, array $keepIds, string $now): int
    {
        if ($keepIds === []) {
            return $this->db->execute(
                "UPDATE game_exam_questions SET deleted_at = ? WHERE exam_id = ? AND deleted_at IS NULL",
                [$now, $examId]
            );
        }
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        return $this->db->execute(
            "UPDATE game_exam_questions SET deleted_at = ?
             WHERE exam_id = ? AND deleted_at IS NULL AND id NOT IN ($placeholders)",
            array_merge([$now, $examId], array_map('intval', $keepIds))
        );
    }

    public function replaceChoices(int $questionId, array $choices): void
    {
        // Only called while the exam has no attempts (DEC-10), so no answer
        // row can reference these choice ids yet.
        $this->db->execute("DELETE FROM game_exam_question_choices WHERE question_id = ?", [$questionId]);
        foreach (array_values($choices) as $i => $choice) {
            $this->db->insert('game_exam_question_choices', [
                'question_id' => $questionId,
                'choice_text' => $choice['choice_text'],
                'is_correct' => (int) $choice['is_correct'],
                'order_number' => $i,
            ]);
        }
    }

    public function refreshCounters(int $examId): void
    {
        $this->db->execute(
            "UPDATE game_exams e
             SET e.question_count = (SELECT COUNT(*) FROM game_exam_questions q WHERE q.exam_id = e.id AND q.deleted_at IS NULL),
                 e.max_points = (SELECT COALESCE(SUM(q.score), 0) FROM game_exam_questions q WHERE q.exam_id = e.id AND q.deleted_at IS NULL)
             WHERE e.id = ?",
            [$examId]
        );
    }

    public function updateQuestionOrder(int $examId, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $i => $id) {
            $this->db->execute(
                "UPDATE game_exam_questions SET order_number = ? WHERE id = ? AND exam_id = ?",
                [$i, (int) $id, $examId]
            );
        }
    }

    public function incrementAttempts(int $examId): void
    {
        $this->db->execute("UPDATE game_exams SET attempts_count = attempts_count + 1 WHERE id = ?", [$examId]);
    }

    public function stageExists(int $stageId): bool
    {
        return $this->db->selectOne("SELECT id FROM stages WHERE id = ? AND deleted_at IS NULL", [$stageId]) !== null;
    }

    public function listForStudent(int $studentId, int $stageId, array $filters, string $nowUtc, int $limit, int $offset): array
    {
        $params = [$studentId];
        $where = [$this->studentVisibility()];
        array_push($params, $stageId, $nowUtc, $nowUtc);

        if (!empty($filters['search'])) {
            [$sql, $p] = SearchSql::build($filters['search'], [], ['e.headline']);
            $where[] = $sql;
            $params = array_merge($params, $p);
        }

        switch ($filters['filter'] ?? 'all') {
            case 'attempted':
                $where[] = 'a.id IS NOT NULL';
                break;
            case 'not_attempted':
                $where[] = 'a.id IS NULL';
                break;
            case 'in_progress':
                $where[] = "a.status = 'in_progress'";
                break;
            case 'passed':
                $where[] = "a.status IN ('completed','expired') AND a.is_passed = 1";
                break;
            case 'failed':
                $where[] = "a.status IN ('completed','expired') AND a.is_passed = 0";
                break;
        }

        $whereSql = implode(' AND ', $where);
        $limit = (int) $limit;
        $offset = (int) $offset;

        $rows = $this->db->query(
            "SELECT e.id, e.stage_id, e.headline, e.description, e.duration_seconds, e.question_count,
                    e.max_points, e.pass_percentage, e.published_at, e.available_until, st.name AS stage_name,
                    a.id AS attempt_id, a.status AS attempt_status, a.started_at AS attempt_started_at,
                    a.expires_at AS attempt_expires_at, a.finished_at AS attempt_finished_at,
                    a.earned_points AS attempt_earned_points, a.deducted_points AS attempt_deducted_points,
                    a.final_points AS attempt_final_points, a.max_points AS attempt_max_points,
                    a.percentage AS attempt_percentage, a.is_passed AS attempt_is_passed,
                    a.grade_key AS attempt_grade_key, a.grade_label AS attempt_grade_label
             FROM game_exams e
             LEFT JOIN game_exam_attempts a ON a.exam_id = e.id AND a.student_id = ?
             LEFT JOIN stages st ON st.id = e.stage_id
             WHERE $whereSql
             ORDER BY (a.status = 'in_progress') DESC, COALESCE(e.published_at, e.created_at) DESC, e.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        $total = $this->db->selectOne(
            "SELECT COUNT(*) AS c
             FROM game_exams e
             LEFT JOIN game_exam_attempts a ON a.exam_id = e.id AND a.student_id = ?
             WHERE $whereSql",
            $params
        );

        return ['rows' => $rows, 'total' => (int) ($total['c'] ?? 0)];
    }

    public function findVisibleForStudent(int $examId, int $studentId, int $stageId, string $nowUtc): ?array
    {
        return $this->db->selectOne(
            "SELECT e.*, st.name AS stage_name, a.id AS attempt_id, a.status AS attempt_status
             FROM game_exams e
             LEFT JOIN game_exam_attempts a ON a.exam_id = e.id AND a.student_id = ?
             LEFT JOIN stages st ON st.id = e.stage_id
             WHERE e.id = ? AND " . $this->studentVisibility(),
            [$studentId, $examId, $stageId, $nowUtc, $nowUtc]
        );
    }

    /**
     * DEC-13 + DEC-11: open to the student's stage while published and in its
     * window, OR any exam the student already has an attempt on (so results
     * and running attempts stay reachable after unpublish/stage change).
     * Expects params in order: stage_id, now, now (after the join's student_id).
     */
    private function studentVisibility(): string
    {
        return "(
            (e.stage_id = ? AND e.status = '" . C::STATUS_PUBLISHED . "' AND e.deleted_at IS NULL
             AND (e.available_from IS NULL OR e.available_from <= ?)
             AND (e.available_until IS NULL OR e.available_until > ?))
            OR a.id IS NOT NULL
        )";
    }

    /** @return array{0:string,1:array} */
    private function adminWhere(array $f, bool $applyStatusFilters): array
    {
        $where = ['1=1'];
        $params = [];

        if ($applyStatusFilters) {
            $deleted = $f['deleted'] ?? 'exclude';
            if ($deleted === 'only') {
                $where[] = 'e.deleted_at IS NOT NULL';
            } elseif ($deleted !== 'include') {
                $where[] = 'e.deleted_at IS NULL';
            }
            if (!empty($f['status'])) {
                $where[] = 'e.status = ?';
                $params[] = $f['status'];
            }
        }
        if (!empty($f['stage_id'])) {
            $where[] = 'e.stage_id = ?';
            $params[] = (int) $f['stage_id'];
        }
        if (!empty($f['created_by'])) {
            $where[] = 'e.created_by = ?';
            $params[] = (int) $f['created_by'];
        }
        $dateCol = ['created_at' => 'e.created_at', 'updated_at' => 'e.updated_at', 'published_at' => 'e.published_at'][$f['date_field'] ?? 'created_at'] ?? 'e.created_at';
        if (!empty($f['date_from_utc'])) {
            $where[] = "$dateCol >= ?";
            $params[] = $f['date_from_utc'];
        }
        if (!empty($f['date_to_utc'])) {
            $where[] = "$dateCol < ?";
            $params[] = $f['date_to_utc'];
        }
        if (isset($f['min_questions']) && $f['min_questions'] !== null) {
            $where[] = 'e.question_count >= ?';
            $params[] = (int) $f['min_questions'];
        }
        if (isset($f['max_questions']) && $f['max_questions'] !== null) {
            $where[] = 'e.question_count <= ?';
            $params[] = (int) $f['max_questions'];
        }
        if (!empty($f['search'])) {
            [$sql, $p] = SearchSql::build($f['search'], ['e.headline', 'e.description'], ['e.headline', 'e.description']);
            $where[] = $sql;
            $params = array_merge($params, $p);
        }

        return [implode(' AND ', $where), $params];
    }
}
 