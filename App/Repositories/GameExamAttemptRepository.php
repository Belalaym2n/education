<?php

namespace App\Repositories;

use App\Contracts\GameExamAttemptRepositoryInterface;
use App\Database\Database;
use App\Support\SearchSql;

class GameExamAttemptRepository implements GameExamAttemptRepositoryInterface
{
    private Database $db;

    private const RESULT_SORTS = [
        'started_at' => 'a.started_at',
        'finished_at' => 'a.finished_at',
        'final_points' => 'a.final_points',
        'percentage' => 'a.percentage',
        'full_name' => 'u.full_name',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne("SELECT * FROM game_exam_attempts WHERE id = ?", [$id]);
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->db->selectOne("SELECT * FROM game_exam_attempts WHERE id = ? FOR UPDATE", [$id]);
    }

    public function findByExamAndStudent(int $examId, int $studentId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM game_exam_attempts WHERE exam_id = ? AND student_id = ?",
            [$examId, $studentId]
        );
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('game_exam_attempts', $data);
    }

  public function update(int $id, array $data): int
{
    return $this->db->update(
        'game_exam_attempts',
        $data,
        'id = ?',
        [$id]
    );
}  


    public function insertAttemptQuestion(array $data): int
    {
        return (int) $this->db->insert('game_exam_attempt_questions', $data);
    }

    public function attemptQuestions(int $attemptId): array
    {
        return $this->db->query(
            "SELECT aq.id AS aq_id, aq.attempt_id, aq.question_id, aq.position, aq.choice_order, aq.state,
                    aq.result, aq.attempts_used, aq.retry_available_at, aq.earned_points, aq.deducted_points,
                    aq.completed_at,
                    q.type, q.question_text, q.score, q.second_attempt_score, q.negative_score, q.retry_seconds,
                    q.character_name, q.character_description, q.character_image_url,
                    q.front_text, q.front_image_url, q.back_text, q.back_image_url, q.info_text
             FROM game_exam_attempt_questions aq
             JOIN game_exam_questions q ON q.id = aq.question_id
             WHERE aq.attempt_id = ?
             ORDER BY aq.position ASC",
            [$attemptId]
        );
    }

    public function attemptQuestionForUpdate(int $attemptId, int $questionId): ?array
    {
        return $this->db->selectOne(
            "SELECT aq.*, q.score, q.second_attempt_score, q.negative_score, q.retry_seconds
             FROM game_exam_attempt_questions aq
             JOIN game_exam_questions q ON q.id = aq.question_id
             WHERE aq.attempt_id = ? AND aq.question_id = ?
             FOR UPDATE",
            [$attemptId, $questionId]
        );
    }

  public function updateAttemptQuestion(int $id, array $data): int
{
    return $this->db->update(
        'game_exam_attempt_questions',
        $data,
        'id = ?',
        [$id]
    );
}    
    public function incrementTotals(int $attemptId, int $earned, int $deducted, int $correct, int $failed): int
    {
        return $this->db->execute(
            "UPDATE game_exam_attempts
             SET earned_points = earned_points + ?,
                 deducted_points = deducted_points + ?,
                 final_points = final_points + ? - ?,
                 correct_count = correct_count + ?,
                 failed_count = failed_count + ?,
                 answers_count = answers_count + 1,
                 sync_version = sync_version + 1
             WHERE id = ?",
            [$earned, $deducted, $earned, $deducted, $correct, $failed, $attemptId]
        );
    }

    public function openQuestionCount(int $attemptId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM game_exam_attempt_questions WHERE attempt_id = ? AND state <> 'completed'",
            [$attemptId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public function closeOpenQuestions(int $attemptId, string $nowUtc): void
    {
        // DEC-6: never answered → unanswered; answered wrong once → wrong_final
        // with no penalty (the student never got the chance to retry).
        $this->db->execute(
            "UPDATE game_exam_attempt_questions
             SET result = CASE WHEN state = 'retry_pending' THEN 'wrong_final' ELSE 'unanswered' END,
                 state = 'completed',
                 completed_at = ?
             WHERE attempt_id = ? AND state <> 'completed'",
            [$nowUtc, $attemptId]
        );
    }

    public function resultCounts(int $attemptId): array
    {
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(result IN ('correct_first','correct_second')), 0) AS correct,
                    COALESCE(SUM(result IN ('wrong_final','unanswered')), 0) AS failed
             FROM game_exam_attempt_questions WHERE attempt_id = ?",
            [$attemptId]
        );
        return ['correct' => (int) ($row['correct'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }

    public function choicesForQuestions(array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }
        $ids = array_map('intval', $questionIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->query(
            "SELECT id, question_id, choice_text, is_correct, order_number
             FROM game_exam_question_choices
             WHERE question_id IN ($placeholders)
             ORDER BY order_number ASC, id ASC",
            $ids
        );
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[(int) $r['question_id']][] = $r;
        }
        return $grouped;
    }

    public function findChoice(int $questionId, int $choiceId): ?array
    {
        return $this->db->selectOne(
            "SELECT id, question_id, choice_text, is_correct FROM game_exam_question_choices WHERE id = ? AND question_id = ?",
            [$choiceId, $questionId]
        );
    }

    public function answerByAction(int $attemptId, string $clientActionId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM game_exam_attempt_answers WHERE attempt_id = ? AND client_action_id = ?",
            [$attemptId, $clientActionId]
        );
    }

    public function insertAnswer(array $data): int
    {
        return (int) $this->db->insert('game_exam_attempt_answers', $data);
    }

    public function answersForAttempt(int $attemptId): array
    {
        $rows = $this->db->query(
            "SELECT ans.id, ans.question_id, ans.attempt_no, ans.choice_id, ans.is_correct, ans.points_delta,
                    ans.answered_at, ans.was_offline, c.choice_text
             FROM game_exam_attempt_answers ans
             LEFT JOIN game_exam_question_choices c ON c.id = ans.choice_id
             WHERE ans.attempt_id = ?
             ORDER BY ans.question_id, ans.attempt_no",
            [$attemptId]
        );
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[(int) $r['question_id']][] = $r;
        }
        return $grouped;
    }

    public function expiredOpenAttemptIds(string $nowUtc, int $limit): array
    {
        $limit = (int) $limit;
        $rows = $this->db->query(
            "SELECT id FROM game_exam_attempts
             WHERE status = 'in_progress'
               AND expires_at < DATE_SUB(?, INTERVAL offline_grace_seconds SECOND)
             ORDER BY expires_at ASC
             LIMIT $limit",
            [$nowUtc]
        );
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    public function results(int $examId, array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$where, $params] = $this->resultsWhere($examId, $filters);
        $orderBy = SearchSql::orderBy($sort, $order, self::RESULT_SORTS, 'started_at');
        $limit = (int) $limit;
        $offset = (int) $offset;

        $rows = $this->db->query(
            "SELECT a.*, s.id AS student_id, s.profile_image, u.full_name, u.email, u.phone, st.name AS stage_name,
                    TIMESTAMPDIFF(SECOND, a.started_at, COALESCE(a.finished_at, LEAST(a.expires_at, a.updated_at))) AS duration_seconds
             FROM game_exam_attempts a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             LEFT JOIN stages st ON st.id = a.stage_id
             WHERE $where
             ORDER BY $orderBy, a.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        $total = $this->db->selectOne(
            "SELECT COUNT(*) AS c
             FROM game_exam_attempts a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             WHERE $where",
            $params
        );

        return ['rows' => $rows, 'total' => (int) ($total['c'] ?? 0)];
    }

    public function resultStatistics(int $examId, array $filters): array
    {
        [$where, $params] = $this->resultsWhere($examId, $filters);

        return $this->db->selectOne(
            "SELECT
                COUNT(*) AS attempts,
                COALESCE(SUM(a.status = 'in_progress'), 0) AS in_progress,
                COALESCE(SUM(a.status <> 'in_progress'), 0) AS finished,
                AVG(CASE WHEN a.status <> 'in_progress' THEN a.final_points END) AS avg_points,
                MAX(CASE WHEN a.status <> 'in_progress' THEN a.final_points END) AS max_points,
                MIN(CASE WHEN a.status <> 'in_progress' THEN a.final_points END) AS min_points,
                AVG(a.percentage) AS avg_percentage,
                COALESCE(SUM(a.is_passed = 1), 0) AS pass_count,
                COALESCE(SUM(a.is_passed = 0), 0) AS fail_count,
                COALESCE(SUM(a.earned_points), 0) AS total_earned,
                COALESCE(SUM(a.deducted_points), 0) AS total_deducted,
                AVG(a.answers_count) AS avg_submissions,
                AVG(CASE WHEN a.status <> 'in_progress'
                         THEN TIMESTAMPDIFF(SECOND, a.started_at, a.finished_at) END) AS avg_duration_seconds
             FROM game_exam_attempts a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             WHERE $where",
            $params
        ) ?? [];
    }

    public function questionDifficulty(int $examId): array
    {
        return $this->db->query(
            "SELECT q.id, q.type, q.question_text, q.order_number, q.score,
                    COUNT(aq.id) AS finished,
                    COALESCE(SUM(aq.result = 'correct_first'), 0) AS correct_first,
                    COALESCE(SUM(aq.result = 'correct_second'), 0) AS correct_second,
                    COALESCE(SUM(aq.result = 'wrong_final'), 0) AS wrong_final,
                    COALESCE(SUM(aq.result = 'unanswered'), 0) AS unanswered
             FROM game_exam_questions q
             LEFT JOIN game_exam_attempt_questions aq ON aq.question_id = q.id AND aq.result IS NOT NULL
             WHERE q.exam_id = ? AND q.deleted_at IS NULL
             GROUP BY q.id, q.type, q.question_text, q.order_number, q.score
             ORDER BY q.order_number ASC, q.id ASC",
            [$examId]
        );
    }

    public function topStudents(string $fromUtc, string $toUtc, ?int $stageId, int $limit): array
    {
        $limit = max(1, (int) $limit);
        $params = [$fromUtc, $toUtc];
        $stageFilter = '';
        if ($stageId !== null) {
            $stageFilter = 'AND l.stage_id = ?';
            $params[] = $stageId;
        }
        $params[] = $limit;

        // One aggregate pass over the month's ledger rows (idx_ledger_stage_month),
        // ranked inside MariaDB; never ranks in PHP.
        return $this->db->query(
            "SELECT ranked.*, st.name AS stage_name
             FROM (
                SELECT l.stage_id, l.student_id, u.full_name, s.profile_image,
                       SUM(l.points) AS month_points,
                       COUNT(DISTINCT l.exam_id) AS exams_count,
                       MAX(l.created_at) AS last_scored_at,
                       RANK() OVER (PARTITION BY l.stage_id
                                    ORDER BY SUM(l.points) DESC, MAX(l.created_at) ASC) AS rank_no
                FROM student_points_ledger l
                JOIN students s ON s.id = l.student_id AND s.deleted_at IS NULL
                JOIN users u ON u.id = s.user_id
                WHERE l.created_at >= ? AND l.created_at < ? $stageFilter
                GROUP BY l.stage_id, l.student_id, u.full_name, s.profile_image
             ) ranked
             LEFT JOIN stages st ON st.id = ranked.stage_id
             WHERE ranked.rank_no <= ?
             ORDER BY st.order_number ASC, ranked.rank_no ASC, ranked.full_name ASC",
            $params
        );
    }

    public function attemptDetail(int $attemptId): ?array
    {
        return $this->db->selectOne(
            "SELECT a.*, s.id AS student_id, s.profile_image, s.points AS student_points,
                    u.full_name, u.email, u.phone, st.name AS stage_name,
                    e.headline AS exam_headline, e.description AS exam_description,
                    e.duration_seconds AS exam_duration_seconds, e.question_count AS exam_question_count
             FROM game_exam_attempts a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             JOIN game_exams e ON e.id = a.exam_id
             LEFT JOIN stages st ON st.id = a.stage_id
             WHERE a.id = ?",
            [$attemptId]
        );
    }

    /** @return array{0:string,1:array} */
    private function resultsWhere(int $examId, array $f): array
    {
        $where = ['a.exam_id = ?'];
        $params = [$examId];

        if (!empty($f['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $f['status'];
        }
        if (isset($f['is_passed']) && $f['is_passed'] !== null) {
            $where[] = 'a.is_passed = ?';
            $params[] = $f['is_passed'] ? 1 : 0;
        }
        if (!empty($f['from_utc'])) {
            $where[] = 'a.started_at >= ?';
            $params[] = $f['from_utc'];
        }
        if (!empty($f['to_utc'])) {
            $where[] = 'a.started_at < ?';
            $params[] = $f['to_utc'];
        }
        if (!empty($f['search'])) {
            $term = trim($f['search']);
            $like = '%' . SearchSql::escapeLike($term) . '%';
            $cond = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?';
            array_push($params, $like, $like, $like);
            if (ctype_digit($term)) {
                $cond .= ' OR s.id = ?';
                $params[] = (int) $term;
            }
            $where[] = $cond . ')';
        }

        return [implode(' AND ', $where), $params];
    }
}
