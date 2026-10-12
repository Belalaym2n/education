<?php

namespace App\Repositories;

use App\Contracts\DashboardAnalyticsRepositoryInterface;
use App\Database\Database;
use App\Support\AnalyticsPeriod;

/**
 * Aggregate-only SQL for the teacher dashboard. Never returns raw student
 * rows except the bounded recent-activity feed.
 *
 * TIME ZONES. Two storage conventions exist in this schema:
 *  - "local": columns filled by MySQL defaults / NOW() — stored in the DB
 *    session time zone (students, lectures, ownerships, attendance, ...).
 *  - "utc": columns written by App\Support\Clock (game exams + points
 *    ledger) — stored as UTC wall-clock strings.
 * Window boundaries are always local; for utc columns the boundary is
 * converted with CONVERT_TZ(?, 'SYSTEM', '+00:00') (index-friendly, DST-
 * aware per value) and buckets use CONVERT_TZ(col, '+00:00', 'SYSTEM').
 *
 * Revenue uses lecture_ownerships.created_at (DB clock), not purchased_at,
 * which is written with PHP's date() and can disagree with the DB clock.
 *
 * Named placeholders are reused across a statement (PDO's default emulated
 * prepares allow this); run() binds only the names a statement uses.
 */
class DashboardAnalyticsRepository implements DashboardAnalyticsRepositoryInterface
{
    private const QUIZ_SUBMITTED = "qa.status IN ('submitted','auto_submitted')";
    private const QUIZ_GRADED = "qa.status IN ('submitted','auto_submitted') AND qa.review_status IN ('not_required','completed') AND qa.percentage IS NOT NULL";
    private const GAME_FINISHED = "a.status IN ('completed','expired')";
    private const GAME_GRADED = "a.status IN ('completed','expired') AND a.percentage IS NOT NULL";
    private const GAME_FINISHED_AT = "COALESCE(a.finished_at, a.expires_at)";

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Meta
    // ------------------------------------------------------------------

    public function localNow(): string
    {
        $row = $this->db->selectOne("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS local_now");
        return (string) $row['local_now'];
    }

    public function stages(): array
    {
        $rows = $this->db->query(
            "SELECT id, name, order_number FROM stages WHERE deleted_at IS NULL ORDER BY order_number ASC, id ASC"
        );
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'order_number' => (int) $r['order_number'],
        ], $rows);
    }

    public function stageExists(int $stageId): bool
    {
        return $this->db->selectOne(
            "SELECT id FROM stages WHERE id = ? AND deleted_at IS NULL",
            [$stageId]
        ) !== null;
    }

    public function viewerName(int $userId): ?string
    {
        $row = $this->db->selectOne("SELECT full_name FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
        return $row['full_name'] ?? null;
    }

    // ------------------------------------------------------------------
    // Stats (one row, or one row per stage)
    // ------------------------------------------------------------------

    public function studentStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('s.stage_id', $stageId, $byStage);
        $cur = $this->in('s.created_at', 'cur');
        $prev = $this->in('s.created_at', 'prev');

        return $this->run("
            SELECT $sel
                COUNT(*) AS total,
                COALESCE(SUM(u.is_active = 1), 0) AS active,
                COALESCE(SUM($cur), 0) AS new_current,
                COALESCE(SUM($prev), 0) AS new_previous
            FROM students s
            INNER JOIN users u ON u.id = s.user_id
            WHERE s.deleted_at IS NULL AND u.deleted_at IS NULL $where
            $group
        ", $p, $stageId);
    }

    public function revenueStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('l.stage_id', $stageId, $byStage);
        $cur = $this->in('lo.created_at', 'cur');
        $prev = $this->in('lo.created_at', 'prev');

        // lectures is 1:1 per ownership row, so the join never multiplies amounts.
        return $this->run("
            SELECT $sel
                COALESCE(SUM(lo.purchase_price), 0) AS total_revenue,
                COUNT(*) AS total_redemptions,
                COALESCE(SUM(CASE WHEN $cur THEN lo.purchase_price END), 0) AS revenue_current,
                COALESCE(SUM($cur), 0) AS redemptions_current,
                COUNT(DISTINCT CASE WHEN $cur THEN lo.student_id END) AS students_current,
                COALESCE(SUM(CASE WHEN $prev THEN lo.purchase_price END), 0) AS revenue_previous,
                COALESCE(SUM($prev), 0) AS redemptions_previous
            FROM lecture_ownerships lo
            INNER JOIN lectures l ON l.id = lo.lecture_id
            WHERE lo.deleted_at IS NULL AND lo.coupon_id IS NOT NULL $where
            $group
        ", $p, $stageId);
    }

    public function couponStats(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('l.stage_id', $stageId, false);
        $cur = $this->in('c.created_at', 'cur');
        $prev = $this->in('c.created_at', 'prev');

        // Status rules mirror CouponRepository::summary() exactly.
        return $this->run("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(c.is_active = 1 AND (c.expire_at IS NULL OR c.expire_at >= NOW()) AND c.times_used < c.max_uses), 0) AS active,
                COALESCE(SUM(c.is_active = 0), 0) AS inactive,
                COALESCE(SUM(c.expire_at IS NOT NULL AND c.expire_at < NOW()), 0) AS expired,
                COALESCE(SUM(c.times_used >= c.max_uses), 0) AS fully_used,
                COALESCE(SUM(c.times_used = 0), 0) AS unused,
                COALESCE(SUM(c.times_used), 0) AS total_uses,
                COALESCE(SUM(c.max_uses), 0) AS total_capacity,
                COALESCE(SUM($cur), 0) AS created_current,
                COALESCE(SUM($prev), 0) AS created_previous
            FROM coupons c
            INNER JOIN lectures l ON l.id = c.lecture_id
            WHERE c.deleted_at IS NULL $where
        ", $p, $stageId)[0] ?? [];
    }

    public function lectureStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('l.stage_id', $stageId, $byStage);
        $cur = $this->in('l.created_at', 'cur');
        $prev = $this->in('l.created_at', 'prev');

        return $this->run("
            SELECT $sel
                COUNT(*) AS total,
                COALESCE(SUM(l.is_published = 1), 0) AS published,
                COALESCE(SUM(l.is_free = 1), 0) AS free,
                COALESCE(SUM($cur), 0) AS created_current,
                COALESCE(SUM($prev), 0) AS created_previous
            FROM lectures l
            WHERE l.deleted_at IS NULL $where
            $group
        ", $p, $stageId);
    }

    public function quizStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('q.stage_id', $stageId, $byStage);
        $cur = $this->in('q.created_at', 'cur');
        $prev = $this->in('q.created_at', 'prev');

        return $this->run("
            SELECT $sel
                COUNT(*) AS total,
                COALESCE(SUM(q.status = 'published'), 0) AS published,
                COALESCE(SUM(q.status = 'draft'), 0) AS draft,
                COALESCE(SUM(q.status = 'archived'), 0) AS archived,
                COALESCE(SUM($cur), 0) AS created_current,
                COALESCE(SUM($prev), 0) AS created_previous
            FROM quizzes q
            WHERE q.deleted_at IS NULL $where
            $group
        ", $p, $stageId);
    }

    public function gameExamStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('e.stage_id', $stageId, $byStage);
        $cur = $this->in('e.created_at', 'cur', true);
        $prev = $this->in('e.created_at', 'prev', true);

        return $this->run("
            SELECT $sel
                COUNT(*) AS total,
                COALESCE(SUM(e.status = 'published'), 0) AS published,
                COALESCE(SUM(e.status = 'draft'), 0) AS draft,
                COALESCE(SUM(e.status = 'archived'), 0) AS archived,
                COALESCE(SUM($cur), 0) AS created_current,
                COALESCE(SUM($prev), 0) AS created_previous
            FROM game_exams e
            WHERE e.deleted_at IS NULL $where
            $group
        ", $p, $stageId);
    }

    public function quizAttemptStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('q.stage_id', $stageId, $byStage);
        $sub = self::QUIZ_SUBMITTED;
        $graded = self::QUIZ_GRADED;
        $cur = $this->in('qa.finished_at', 'cur');
        $prev = $this->in('qa.finished_at', 'prev');

        return $this->run("
            SELECT $sel
                COALESCE(SUM($sub AND $cur), 0) AS submitted_current,
                COALESCE(SUM($graded AND $cur), 0) AS graded_current,
                COALESCE(SUM($graded AND $cur AND qa.is_passed IS NOT NULL), 0) AS pass_evaluated_current,
                COALESCE(SUM($graded AND $cur AND qa.is_passed = 1), 0) AS passed_current,
                COALESCE(SUM(CASE WHEN $graded AND $cur THEN qa.percentage END), 0) AS pct_sum_current,
                COUNT(DISTINCT CASE WHEN $sub AND $cur THEN qa.student_id END) AS students_current,
                COALESCE(SUM($sub AND $prev), 0) AS submitted_previous,
                COALESCE(SUM($graded AND $prev), 0) AS graded_previous,
                COALESCE(SUM($graded AND $prev AND qa.is_passed IS NOT NULL), 0) AS pass_evaluated_previous,
                COALESCE(SUM($graded AND $prev AND qa.is_passed = 1), 0) AS passed_previous,
                COALESCE(SUM(CASE WHEN $graded AND $prev THEN qa.percentage END), 0) AS pct_sum_previous,
                COALESCE(SUM(qa.status = 'in_progress'), 0) AS in_progress_now,
                COALESCE(SUM($sub AND qa.review_status IN ('pending','partial')), 0) AS pending_review_now
            FROM quiz_attempts qa
            INNER JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.deleted_at IS NULL $where
            $group
        ", $p, $stageId);
    }

    public function gameAttemptStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('a.stage_id', $stageId, $byStage);
        $fin = self::GAME_FINISHED;
        $graded = self::GAME_GRADED;
        $cur = $this->in(self::GAME_FINISHED_AT, 'cur', true);
        $prev = $this->in(self::GAME_FINISHED_AT, 'prev', true);

        return $this->run("
            SELECT $sel
                COALESCE(SUM($fin AND $cur), 0) AS submitted_current,
                COALESCE(SUM($graded AND $cur), 0) AS graded_current,
                COALESCE(SUM($graded AND $cur AND a.is_passed IS NOT NULL), 0) AS pass_evaluated_current,
                COALESCE(SUM($graded AND $cur AND a.is_passed = 1), 0) AS passed_current,
                COALESCE(SUM(CASE WHEN $graded AND $cur THEN a.percentage END), 0) AS pct_sum_current,
                COUNT(DISTINCT CASE WHEN $fin AND $cur THEN a.student_id END) AS students_current,
                COALESCE(SUM($fin AND $prev), 0) AS submitted_previous,
                COALESCE(SUM($graded AND $prev), 0) AS graded_previous,
                COALESCE(SUM($graded AND $prev AND a.is_passed IS NOT NULL), 0) AS pass_evaluated_previous,
                COALESCE(SUM($graded AND $prev AND a.is_passed = 1), 0) AS passed_previous,
                COALESCE(SUM(CASE WHEN $graded AND $prev THEN a.percentage END), 0) AS pct_sum_previous,
                COALESCE(SUM(a.status = 'in_progress'), 0) AS in_progress_now,
                0 AS pending_review_now
            FROM game_exam_attempts a
            WHERE 1 = 1 $where
            $group
        ", $p, $stageId);
    }

    public function attendanceStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('s.stage_id', $stageId, $byStage);
        $cur = $this->in('ar.recorded_at', 'cur');
        $prev = $this->in('ar.recorded_at', 'prev');

        // Only rows inside either window are scanned.
        return $this->run("
            SELECT $sel
                COALESCE(SUM($cur), 0) AS records_current,
                COALESCE(SUM($cur AND ar.status = 'present'), 0) AS present_current,
                COALESCE(SUM($cur AND ar.status = 'late'), 0) AS late_current,
                COALESCE(SUM($cur AND ar.status = 'absent'), 0) AS absent_current,
                COUNT(DISTINCT CASE WHEN $cur THEN ar.student_id END) AS students_current,
                COALESCE(SUM($prev), 0) AS records_previous,
                COALESCE(SUM($prev AND ar.status = 'present'), 0) AS present_previous,
                COALESCE(SUM($prev AND ar.status = 'late'), 0) AS late_previous,
                COALESCE(SUM($prev AND ar.status = 'absent'), 0) AS absent_previous
            FROM attendance_records ar
            INNER JOIN students s ON s.id = ar.student_id
            WHERE ar.recorded_at >= :prev_from AND ar.recorded_at < :cur_to $where
            $group
        ", $p, $stageId);
    }

    public function pointsStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('lp.stage_id', $stageId, $byStage);
        $cur = $this->in('lp.created_at', 'cur', true);
        $prev = $this->in('lp.created_at', 'prev', true);

        // The ledger has UNIQUE(source_type, source_id), so retries and
        // duplicate sync requests can never be counted twice.
        return $this->run("
            SELECT $sel
                COALESCE(SUM(lp.points), 0) AS total_points,
                COALESCE(SUM(CASE WHEN $cur THEN lp.points END), 0) AS points_current,
                COALESCE(SUM(CASE WHEN $prev THEN lp.points END), 0) AS points_previous,
                COUNT(DISTINCT CASE WHEN $cur THEN lp.student_id END) AS students_current,
                COUNT(DISTINCT lp.student_id) AS students_all
            FROM student_points_ledger lp
            WHERE 1 = 1 $where
            $group
        ", $p, $stageId);
    }

    public function notificationStats(AnalyticsPeriod $p): array
    {
        $cur = $this->in('n.created_at', 'cur');
        $prev = $this->in('n.created_at', 'prev');
        $sentCur = $this->in('n.sent_at', 'cur');
        $sentPrev = $this->in('n.sent_at', 'prev');

        return $this->run("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(n.sent_at IS NOT NULL), 0) AS total_sent,
                COALESCE(SUM(n.sent_at IS NULL AND n.send_now = 0 AND n.scheduled_at IS NOT NULL), 0) AS scheduled_pending,
                COALESCE(SUM($cur), 0) AS created_current,
                COALESCE(SUM($prev), 0) AS created_previous,
                COALESCE(SUM($sentCur), 0) AS sent_current,
                COALESCE(SUM($sentPrev), 0) AS sent_previous
            FROM notifications n
            WHERE n.deleted_at IS NULL
        ", $p, null)[0] ?? [];
    }

    public function notificationRecipientStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array
    {
        [$sel, $where, $group] = $this->scope('s.stage_id', $stageId, $byStage);
        // Grade attribution needs the recipient to be a student; for the
        // platform-wide figure parents are included too.
        $join = ($byStage || $stageId !== null)
            ? 'INNER JOIN students s ON s.user_id = nr.user_id'
            : 'LEFT JOIN students s ON s.user_id = nr.user_id';
        $cur = $this->in('nr.created_at', 'cur');
        $prev = $this->in('nr.created_at', 'prev');

        return $this->run("
            SELECT $sel
                COALESCE(SUM($cur), 0) AS recipients_current,
                COALESCE(SUM($cur AND nr.is_read = 1), 0) AS read_current,
                COUNT(DISTINCT CASE WHEN $cur THEN nr.notification_id END) AS notifications_current,
                COALESCE(SUM($prev), 0) AS recipients_previous,
                COALESCE(SUM($prev AND nr.is_read = 1), 0) AS read_previous,
                COUNT(DISTINCT CASE WHEN $prev THEN nr.notification_id END) AS notifications_previous
            FROM notification_recipients nr
            INNER JOIN notifications n ON n.id = nr.notification_id AND n.deleted_at IS NULL
            $join
            WHERE nr.created_at >= :prev_from AND nr.created_at < :cur_to $where
            $group
        ", $p, $stageId);
    }

    public function dispatchStats(AnalyticsPeriod $p): array
    {
        $cur = $this->in('d.created_at', 'cur');

        return $this->run("
            SELECT
                COUNT(*) AS total_logs,
                COALESCE(SUM($cur), 0) AS logs_current,
                COALESCE(SUM($cur AND d.status = 'success'), 0) AS success_current,
                COALESCE(SUM($cur AND d.status IN ('failed','invalid_token')), 0) AS failed_current
            FROM notification_dispatch_logs d
        ", $p, null)[0] ?? [];
    }

    // ------------------------------------------------------------------
    // Time series (current window only, bucketed in local time)
    // ------------------------------------------------------------------

    public function studentSeries(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('s.stage_id', $stageId, false);
        $bucket = $p->bucketSql('s.created_at');
        $cur = $this->in('s.created_at', 'cur');

        return $this->keyed($this->run("
            SELECT $bucket AS bucket, COUNT(*) AS new_students
            FROM students s
            INNER JOIN users u ON u.id = s.user_id
            WHERE s.deleted_at IS NULL AND u.deleted_at IS NULL AND $cur $where
            GROUP BY bucket
        ", $p, $stageId));
    }

    public function revenueSeries(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('l.stage_id', $stageId, false);
        $bucket = $p->bucketSql('lo.created_at');
        $cur = $this->in('lo.created_at', 'cur');

        return $this->keyed($this->run("
            SELECT $bucket AS bucket,
                   COALESCE(SUM(lo.purchase_price), 0) AS revenue,
                   COUNT(*) AS redemptions
            FROM lecture_ownerships lo
            INNER JOIN lectures l ON l.id = lo.lecture_id
            WHERE lo.deleted_at IS NULL AND lo.coupon_id IS NOT NULL AND $cur $where
            GROUP BY bucket
        ", $p, $stageId));
    }

    public function quizAttemptSeries(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('q.stage_id', $stageId, false);
        $bucket = $p->bucketSql('qa.finished_at');
        $graded = self::QUIZ_GRADED;
        $cur = $this->in('qa.finished_at', 'cur');

        return $this->keyed($this->run("
            SELECT $bucket AS bucket,
                   COUNT(*) AS graded,
                   COALESCE(SUM(qa.is_passed IS NOT NULL), 0) AS pass_evaluated,
                   COALESCE(SUM(qa.is_passed = 1), 0) AS passed,
                   COALESCE(SUM(qa.percentage), 0) AS pct_sum
            FROM quiz_attempts qa
            INNER JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.deleted_at IS NULL AND $graded AND $cur $where
            GROUP BY bucket
        ", $p, $stageId));
    }

    public function gameAttemptSeries(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('a.stage_id', $stageId, false);
        $bucket = $p->bucketSql("CONVERT_TZ(" . self::GAME_FINISHED_AT . ", '+00:00', 'SYSTEM')");
        $graded = self::GAME_GRADED;
        $cur = $this->in(self::GAME_FINISHED_AT, 'cur', true);

        return $this->keyed($this->run("
            SELECT $bucket AS bucket,
                   COUNT(*) AS graded,
                   COALESCE(SUM(a.is_passed IS NOT NULL), 0) AS pass_evaluated,
                   COALESCE(SUM(a.is_passed = 1), 0) AS passed,
                   COALESCE(SUM(a.percentage), 0) AS pct_sum
            FROM game_exam_attempts a
            WHERE $graded AND $cur $where
            GROUP BY bucket
        ", $p, $stageId));
    }

    public function attendanceSeries(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('s.stage_id', $stageId, false);
        $bucket = $p->bucketSql('ar.recorded_at');
        $cur = $this->in('ar.recorded_at', 'cur');

        return $this->keyed($this->run("
            SELECT $bucket AS bucket,
                   COUNT(*) AS records,
                   COALESCE(SUM(ar.status = 'present'), 0) AS present,
                   COALESCE(SUM(ar.status = 'late'), 0) AS late,
                   COALESCE(SUM(ar.status = 'absent'), 0) AS absent
            FROM attendance_records ar
            INNER JOIN students s ON s.id = ar.student_id
            WHERE $cur $where
            GROUP BY bucket
        ", $p, $stageId));
    }

    public function pointsSeries(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('lp.stage_id', $stageId, false);
        $bucket = $p->bucketSql("CONVERT_TZ(lp.created_at, '+00:00', 'SYSTEM')");
        $cur = $this->in('lp.created_at', 'cur', true);

        return $this->keyed($this->run("
            SELECT $bucket AS bucket, COALESCE(SUM(lp.points), 0) AS points
            FROM student_points_ledger lp
            WHERE $cur $where
            GROUP BY bucket
        ", $p, $stageId));
    }

    public function notificationSeries(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $where] = $this->scope('s.stage_id', $stageId, false);
        $join = $stageId !== null
            ? 'INNER JOIN students s ON s.user_id = nr.user_id'
            : '';
        $bucket = $p->bucketSql('nr.created_at');
        $cur = $this->in('nr.created_at', 'cur');

        return $this->keyed($this->run("
            SELECT $bucket AS bucket,
                   COUNT(*) AS recipients,
                   COALESCE(SUM(nr.is_read = 1), 0) AS read_count
            FROM notification_recipients nr
            INNER JOIN notifications n ON n.id = nr.notification_id AND n.deleted_at IS NULL
            $join
            WHERE $cur $where
            GROUP BY bucket
        ", $p, $stageId));
    }

    // ------------------------------------------------------------------
    // Distributions, rankings, activity
    // ------------------------------------------------------------------

    public function scoreDistribution(AnalyticsPeriod $p, ?int $stageId): array
    {
        [, $quizWhere] = $this->scope('q.stage_id', $stageId, false);
        [, $gameWhere] = $this->scope('a.stage_id', $stageId, false);
        $quizGraded = self::QUIZ_GRADED;
        $gameGraded = self::GAME_GRADED;
        $quizCur = $this->in('qa.finished_at', 'cur');
        $gameCur = $this->in(self::GAME_FINISHED_AT, 'cur', true);

        $rows = $this->run("
            SELECT
                CASE
                    WHEN x.pct < 50 THEN '0-49'
                    WHEN x.pct < 65 THEN '50-64'
                    WHEN x.pct < 75 THEN '65-74'
                    WHEN x.pct < 85 THEN '75-84'
                    ELSE '85-100'
                END AS band,
                COUNT(*) AS attempts
            FROM (
                SELECT qa.percentage AS pct
                FROM quiz_attempts qa
                INNER JOIN quizzes q ON q.id = qa.quiz_id
                WHERE qa.deleted_at IS NULL AND $quizGraded AND $quizCur $quizWhere
                UNION ALL
                SELECT a.percentage AS pct
                FROM game_exam_attempts a
                WHERE $gameGraded AND $gameCur $gameWhere
            ) x
            GROUP BY band
        ", $p, $stageId);

        $bands = ['0-49' => 0, '50-64' => 0, '65-74' => 0, '75-84' => 0, '85-100' => 0];
        foreach ($rows as $row) {
            $bands[(string) $row['band']] = (int) $row['attempts'];
        }
        return $bands;
    }

    public function topLectures(AnalyticsPeriod $p, ?int $stageId, int $limit): array
    {
        [, $where] = $this->scope('l.stage_id', $stageId, false);
        $cur = $this->in('lo.created_at', 'cur');
        $limit = max(1, min($limit, 20));

        return $this->run("
            SELECT l.id AS lecture_id, l.title, l.stage_id, st.name AS stage_name,
                   COALESCE(SUM(lo.purchase_price), 0) AS revenue,
                   COUNT(*) AS redemptions
            FROM lecture_ownerships lo
            INNER JOIN lectures l ON l.id = lo.lecture_id
            LEFT JOIN stages st ON st.id = l.stage_id
            WHERE lo.deleted_at IS NULL AND lo.coupon_id IS NOT NULL AND $cur $where
            GROUP BY l.id, l.title, l.stage_id, st.name
            ORDER BY revenue DESC, redemptions DESC, l.id ASC
            LIMIT $limit
        ", $p, $stageId);
    }

    public function recentActivity(?int $stageId, int $limit): array
    {
        $limit = max(1, min($limit, 50));
        $sf = fn(string $col) => $stageId !== null ? "AND $col = :stage_id" : '';
        $notifScope = $stageId !== null
            ? "AND (n.target_type = 'all' OR (n.target_type = 'stage' AND n.stage_id = :stage_id))"
            : '';
        $gameAt = "CONVERT_TZ(a.finished_at, '+00:00', 'SYSTEM')";

        $sql = "
            SELECT * FROM (
                (SELECT 'student_registered' AS type, s.created_at AS occurred_at,
                        u.full_name AS actor, st.name AS stage_name, NULL AS subject, NULL AS amount
                 FROM students s
                 INNER JOIN users u ON u.id = s.user_id
                 LEFT JOIN stages st ON st.id = s.stage_id
                 WHERE s.deleted_at IS NULL AND u.deleted_at IS NULL {$sf('s.stage_id')}
                 ORDER BY s.created_at DESC LIMIT $limit)
                UNION ALL
                (SELECT 'coupon_redeemed', lo.created_at, u.full_name, st.name, l.title, lo.purchase_price
                 FROM lecture_ownerships lo
                 INNER JOIN students s ON s.id = lo.student_id
                 INNER JOIN users u ON u.id = s.user_id
                 INNER JOIN lectures l ON l.id = lo.lecture_id
                 LEFT JOIN stages st ON st.id = l.stage_id
                 WHERE lo.deleted_at IS NULL AND lo.coupon_id IS NOT NULL {$sf('l.stage_id')}
                 ORDER BY lo.created_at DESC LIMIT $limit)
                UNION ALL
                (SELECT 'quiz_submitted', qa.finished_at, u.full_name, st.name, q.headline, qa.percentage
                 FROM quiz_attempts qa
                 INNER JOIN quizzes q ON q.id = qa.quiz_id
                 INNER JOIN students s ON s.id = qa.student_id
                 INNER JOIN users u ON u.id = s.user_id
                 LEFT JOIN stages st ON st.id = q.stage_id
                 WHERE qa.deleted_at IS NULL AND qa.finished_at IS NOT NULL
                   AND qa.status IN ('submitted','auto_submitted') {$sf('q.stage_id')}
                 ORDER BY qa.finished_at DESC LIMIT $limit)
                UNION ALL
                (SELECT 'game_exam_completed', $gameAt, u.full_name, st.name, e.headline, a.percentage
                 FROM game_exam_attempts a
                 INNER JOIN game_exams e ON e.id = a.exam_id
                 INNER JOIN students s ON s.id = a.student_id
                 INNER JOIN users u ON u.id = s.user_id
                 LEFT JOIN stages st ON st.id = a.stage_id
                 WHERE a.finished_at IS NOT NULL AND a.status IN ('completed','expired') {$sf('a.stage_id')}
                 ORDER BY a.finished_at DESC LIMIT $limit)
                UNION ALL
                (SELECT 'lecture_created', l.created_at, NULL, st.name, l.title, NULL
                 FROM lectures l
                 LEFT JOIN stages st ON st.id = l.stage_id
                 WHERE l.deleted_at IS NULL {$sf('l.stage_id')}
                 ORDER BY l.created_at DESC LIMIT $limit)
                UNION ALL
                (SELECT 'notification_sent', n.sent_at, NULL, st.name, n.headline, NULL
                 FROM notifications n
                 LEFT JOIN stages st ON st.id = n.stage_id
                 WHERE n.deleted_at IS NULL AND n.sent_at IS NOT NULL $notifScope
                 ORDER BY n.sent_at DESC LIMIT $limit)
            ) feed
            ORDER BY occurred_at DESC
            LIMIT $limit
        ";

        $params = $stageId !== null ? ['stage_id' => $stageId] : [];
        return $this->db->query($sql, $params);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** "$col is inside window $w" — $w is 'cur' or 'prev'. */
    private function in(string $col, string $w, bool $utcColumn = false): string
    {
        $from = ":{$w}_from";
        $to = ":{$w}_to";
        if ($utcColumn) {
            $from = "CONVERT_TZ($from, 'SYSTEM', '+00:00')";
            $to = "CONVERT_TZ($to, 'SYSTEM', '+00:00')";
        }
        return "($col >= $from AND $col < $to)";
    }

    /** @return array{0:string,1:string,2:string} [select prefix, where, group by] */
    private function scope(string $stageCol, ?int $stageId, bool $byStage): array
    {
        return [
            $byStage ? "$stageCol AS stage_id," : '',
            $stageId !== null ? "AND $stageCol = :stage_id" : '',
            $byStage ? "GROUP BY $stageCol" : '',
        ];
    }

    private function run(string $sql, AnalyticsPeriod $p, ?int $stageId): array
    {
        $all = [
            'cur_from' => $p->from,
            'cur_to' => $p->to,
            'prev_from' => $p->prevFrom,
            'prev_to' => $p->prevTo,
            'stage_id' => $stageId,
        ];
        $params = [];
        foreach ($all as $name => $value) {
            if (preg_match('/:' . $name . '\b/', $sql)) {
                $params[$name] = $value;
            }
        }
        return $this->db->query($sql, $params);
    }

    private function keyed(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['bucket']] = $row;
        }
        return $out;
    }
}
