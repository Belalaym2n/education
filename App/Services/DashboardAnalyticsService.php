<?php

namespace App\Services;

use App\Contracts\DashboardAnalyticsRepositoryInterface;
use App\Core\Logger;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Support\AnalyticsPeriod;
use App\Support\AnalyticsThresholds as T;

/**
 * Builds the teacher dashboard payload.
 *
 * Each section is computed independently: a failing query marks only that
 * section as `error` (logged server-side, never leaked) so the rest of the
 * dashboard still renders. A failed section is never reported as zeros.
 */
class DashboardAnalyticsService
{
    public const CURRENCY = 'EGP';
    private const SCORE_BANDS = ['0-49', '50-64', '65-74', '75-84', '85-100'];

    private array $memo = [];

    public function __construct(
        private DashboardAnalyticsRepositoryInterface $repo,
        private GradePerformanceEvaluator $evaluator,
        private DashboardInsightBuilder $insights,
        private Logger $logger,
    ) {}

    public function overview(int $viewerUserId, string $preset, ?string $from, ?string $to, ?int $stageId): array
    {
        if ($stageId !== null && !$this->repo->stageExists($stageId)) {
            throw new ValidationException(['stage_id' => 'Unknown stage']);
        }

        $p = AnalyticsPeriod::resolve($preset, $from, $to, $this->repo->localNow());
        $this->memo = [];

        $sections = [
            'students' => $this->section('students', fn() => $this->students($p, $stageId)),
            'revenue' => $this->section('revenue', fn() => $this->revenue($p, $stageId)),
            'coupons' => $this->section('coupons', fn() => $this->coupons($p, $stageId)),
            'content' => $this->section('content', fn() => $this->content($p, $stageId)),
            'assessments' => $this->section('assessments', fn() => $this->assessments($p, $stageId)),
            'attendance' => $this->section('attendance', fn() => $this->attendance($p, $stageId)),
            'notifications' => $this->section('notifications', fn() => $this->notifications($p, $stageId)),
            'points' => $this->section('points', fn() => $this->points($p, $stageId)),
            'grades' => $this->section('grades', fn() => ['rows' => $this->gradeRows($p, $stageId)]),
        ];
        $sections['insights'] = $this->section('insights', fn() => ['items' => $this->insights->build($sections)]);

        return [
            'meta' => [
                'generated_at' => $p->to,
                'time_basis' => 'database_session_time',
                'currency' => self::CURRENCY,
                'stage_id' => $stageId,
                'period' => $p->toArray(),
                'viewer' => ['name' => $this->safe(fn() => $this->repo->viewerName($viewerUserId))],
                'stages' => $this->safe(fn() => $this->repo->stages()) ?? [],
                'thresholds' => T::toArray(),
            ],
            'sections' => $sections,
        ];
    }

    public function activity(?int $stageId, int $limit): array
    {
        if ($stageId !== null && !$this->repo->stageExists($stageId)) {
            throw new ValidationException(['stage_id' => 'Unknown stage']);
        }

        return array_map(fn($r) => [
            'type' => (string) $r['type'],
            'occurred_at' => (string) $r['occurred_at'],
            'actor' => $r['actor'],
            'stage_name' => $r['stage_name'],
            'subject' => $r['subject'],
            'amount' => $r['amount'] === null ? null : round((float) $r['amount'], 2),
        ], $this->repo->recentActivity($stageId, $limit));
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    private function students(\App\Support\AnalyticsPeriod $p, ?int $stageId): array
    {
        $r = $this->repo->studentStats($p, $stageId)[0] ?? [];
        $total = $this->i($r, 'total');
        $active = $this->i($r, 'active');
        $series = $this->repo->studentSeries($p, $stageId);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'new' => $this->pair($r, 'new'),
            'series' => $this->series($p, $series, fn($row) => ['new_students' => $this->i($row, 'new_students')]),
        ];
    }

    private function revenue(AnalyticsPeriod $p, ?int $stageId): array
    {
        $r = $this->revenueRow($p, $stageId);
        $redemptionsCur = $this->i($r, 'redemptions_current');
        $revenueCur = $this->f($r, 'revenue_current');
        $series = $this->repo->revenueSeries($p, $stageId);

        return [
            'currency' => self::CURRENCY,
            'basis' => 'coupon_redemption_recorded_price',
            'refunds_tracked' => false,
            'total_all_time' => $this->f($r, 'total_revenue'),
            'redemptions_all_time' => $this->i($r, 'total_redemptions'),
            'revenue' => ['current' => $revenueCur, 'previous' => $this->f($r, 'revenue_previous')],
            'redemptions' => $this->pair($r, 'redemptions'),
            'paying_students_current' => $this->i($r, 'students_current'),
            'avg_per_redemption_current' => $redemptionsCur > 0 ? round($revenueCur / $redemptionsCur, 2) : null,
            'series' => $this->series($p, $series, fn($row) => [
                'revenue' => $this->f($row, 'revenue'),
                'redemptions' => $this->i($row, 'redemptions'),
            ]),
            'top_lectures' => array_map(fn($l) => [
                'lecture_id' => (int) $l['lecture_id'],
                'title' => (string) $l['title'],
                'stage_id' => (int) $l['stage_id'],
                'stage_name' => $l['stage_name'],
                'revenue' => round((float) $l['revenue'], 2),
                'redemptions' => (int) $l['redemptions'],
            ], $this->repo->topLectures($p, $stageId, 5)),
        ];
    }

    private function coupons(AnalyticsPeriod $p, ?int $stageId): array
    {
        $c = $this->repo->couponStats($p, $stageId);
        $r = $this->revenueRow($p, $stageId);
        $uses = $this->i($c, 'total_uses');
        $capacity = $this->i($c, 'total_capacity');

        return [
            'total' => $this->i($c, 'total'),
            'active' => $this->i($c, 'active'),
            'inactive' => $this->i($c, 'inactive'),
            'expired' => $this->i($c, 'expired'),
            'fully_used' => $this->i($c, 'fully_used'),
            'unused' => $this->i($c, 'unused'),
            'total_uses' => $uses,
            'total_capacity' => $capacity,
            'usage_rate' => T::rate($uses, $capacity),
            'created' => $this->pair($c, 'created'),
            'redemptions' => $this->pair($r, 'redemptions'),
            'revenue' => ['current' => $this->f($r, 'revenue_current'), 'previous' => $this->f($r, 'revenue_previous')],
            'payment_tracking' => false,
        ];
    }

    private function content(AnalyticsPeriod $p, ?int $stageId): array
    {
        $l = $this->repo->lectureStats($p, $stageId)[0] ?? [];
        $q = $this->repo->quizStats($p, $stageId)[0] ?? [];
        $g = $this->repo->gameExamStats($p, $stageId)[0] ?? [];
        $lt = $this->i($l, 'total');
        $lpub = $this->i($l, 'published');
        $lfree = $this->i($l, 'free');

        $exam = fn(array $row) => [
            'total' => $this->i($row, 'total'),
            'published' => $this->i($row, 'published'),
            'draft' => $this->i($row, 'draft'),
            'archived' => $this->i($row, 'archived'),
            'created' => $this->pair($row, 'created'),
        ];

        return [
            'lectures' => [
                'total' => $lt,
                'published' => $lpub,
                'unpublished' => $lt - $lpub,
                'free' => $lfree,
                'paid' => $lt - $lfree,
                'created' => $this->pair($l, 'created'),
            ],
            'quizzes' => $exam($q),
            'game_exams' => $exam($g),
            'progress_tracking' => false,
        ];
    }

    private function assessments(AnalyticsPeriod $p, ?int $stageId): array
    {
        $quiz = $this->attemptBlock($this->repo->quizAttemptStats($p, $stageId)[0] ?? []);
        $game = $this->attemptBlock($this->repo->gameAttemptStats($p, $stageId)[0] ?? []);

        // Attempt-weighted across both exam systems (not an average of averages).
        $combined = [];
        foreach (['current', 'previous'] as $w) {
            $graded = $quiz['graded'][$w] + $game['graded'][$w];
            $evaluated = $quiz['pass_evaluated'][$w] + $game['pass_evaluated'][$w];
            $passed = $quiz['passed'][$w] + $game['passed'][$w];
            $pctSum = $quiz['_pct_sum'][$w] + $game['_pct_sum'][$w];
            $combined['graded'][$w] = $graded;
            $combined['pass_evaluated'][$w] = $evaluated;
            $combined['passed'][$w] = $passed;
            $combined['pass_rate'][$w] = T::rate($passed, $evaluated);
            $combined['avg_pct'][$w] = $graded > 0 ? round($pctSum / $graded, 2) : null;
        }
        unset($quiz['_pct_sum'], $game['_pct_sum']);

        $qs = $this->repo->quizAttemptSeries($p, $stageId);
        $gs = $this->repo->gameAttemptSeries($p, $stageId);
        $series = [];
        foreach ($p->bucketKeys() as $key) {
            $a = $qs[$key] ?? [];
            $b = $gs[$key] ?? [];
            $graded = $this->i($a, 'graded') + $this->i($b, 'graded');
            $evaluated = $this->i($a, 'pass_evaluated') + $this->i($b, 'pass_evaluated');
            $passed = $this->i($a, 'passed') + $this->i($b, 'passed');
            $pctSum = $this->f($a, 'pct_sum') + $this->f($b, 'pct_sum');
            $series[] = [
                'bucket' => $key,
                'graded' => $graded,
                'passed' => $passed,
                'pass_rate' => T::rate($passed, $evaluated),
                'avg_pct' => $graded > 0 ? round($pctSum / $graded, 2) : null,
            ];
        }

        $dist = $this->repo->scoreDistribution($p, $stageId);

        return [
            'quiz' => $quiz,
            'game' => $game,
            'combined' => $combined,
            'score_distribution' => array_map(
                fn($band) => ['band' => $band, 'attempts' => (int) ($dist[$band] ?? 0)],
                self::SCORE_BANDS
            ),
            'series' => $series,
        ];
    }

    private function attendance(AnalyticsPeriod $p, ?int $stageId): array
    {
        $r = $this->repo->attendanceStats($p, $stageId)[0] ?? [];
        $out = [
            'records' => $this->pair($r, 'records'),
            'present' => $this->pair($r, 'present'),
            'late' => $this->pair($r, 'late'),
            'absent' => $this->pair($r, 'absent'),
            'students_current' => $this->i($r, 'students_current'),
        ];
        foreach (['current', 'previous'] as $w) {
            $records = $out['records'][$w];
            // Same definition as AttendanceRepository::statistics(): present / recorded.
            $out['attendance_rate'][$w] = T::rate($out['present'][$w], $records);
            $out['attended_incl_late_rate'][$w] = T::rate($out['present'][$w] + $out['late'][$w], $records);
            $out['absence_rate'][$w] = T::rate($out['absent'][$w], $records);
        }
        $series = $this->repo->attendanceSeries($p, $stageId);
        $out['series'] = $this->series($p, $series, fn($row) => [
            'records' => $this->i($row, 'records'),
            'present' => $this->i($row, 'present'),
            'late' => $this->i($row, 'late'),
            'absent' => $this->i($row, 'absent'),
        ]);
        return $out;
    }

    private function notifications(AnalyticsPeriod $p, ?int $stageId): array
    {
        $rec = $this->repo->notificationRecipientStats($p, $stageId)[0] ?? [];
        $dispatch = $this->repo->dispatchStats($p);
        $platform = $stageId === null ? $this->repo->notificationStats($p) : null;

        $out = [
            'scope' => $stageId === null ? 'platform' : 'grade',
            'total' => $platform ? $this->i($platform, 'total') : null,
            'total_sent' => $platform ? $this->i($platform, 'total_sent') : null,
            'scheduled_pending' => $platform ? $this->i($platform, 'scheduled_pending') : null,
            'created' => $platform ? $this->pair($platform, 'created') : null,
            'sent' => $platform ? $this->pair($platform, 'sent') : null,
            'reached' => $this->pair($rec, 'notifications'),
            'recipients' => $this->pair($rec, 'recipients'),
            'read' => $this->pair($rec, 'read'),
            'delivery' => [
                // Delivery is only claimed from FCM dispatch logs, never from a created row.
                'tracked' => $this->i($dispatch, 'total_logs') > 0,
                'logs_current' => $this->i($dispatch, 'logs_current'),
                'success_current' => $this->i($dispatch, 'success_current'),
                'failed_current' => $this->i($dispatch, 'failed_current'),
            ],
        ];
        foreach (['current', 'previous'] as $w) {
            $out['read_rate'][$w] = T::rate($out['read'][$w], $out['recipients'][$w]);
        }
        $series = $this->repo->notificationSeries($p, $stageId);
        $out['series'] = $this->series($p, $series, fn($row) => [
            'recipients' => $this->i($row, 'recipients'),
            'read' => $this->i($row, 'read_count'),
        ]);
        return $out;
    }

    private function points(AnalyticsPeriod $p, ?int $stageId): array
    {
        $r = $this->repo->pointsStats($p, $stageId)[0] ?? [];
        $cur = $this->i($r, 'points_current');
        $students = $this->i($r, 'students_current');
        $series = $this->repo->pointsSeries($p, $stageId);

        return [
            'source' => 'student_points_ledger',
            'total_all_time' => $this->i($r, 'total_points'),
            'points' => ['current' => $cur, 'previous' => $this->i($r, 'points_previous')],
            'earning_students_current' => $students,
            'earning_students_all_time' => $this->i($r, 'students_all'),
            'avg_per_earning_student_current' => $students > 0 ? round($cur / $students, 2) : null,
            'series' => $this->series($p, $series, fn($row) => ['points' => $this->i($row, 'points')]),
        ];
    }

    /** One grouped query per source, merged per stage — never one query per grade. */
    private function gradeRows(AnalyticsPeriod $p, ?int $stageId): array
    {
        $stages = $this->repo->stages();
        if ($stageId !== null) {
            $stages = array_values(array_filter($stages, fn($s) => $s['id'] === $stageId));
        }

        $by = fn(array $rows) => array_column($rows, null, 'stage_id');
        $students = $by($this->repo->studentStats($p, $stageId, true));
        $lectures = $by($this->repo->lectureStats($p, $stageId, true));
        $quizzes = $by($this->repo->quizStats($p, $stageId, true));
        $games = $by($this->repo->gameExamStats($p, $stageId, true));
        $quizAttempts = $by($this->repo->quizAttemptStats($p, $stageId, true));
        $gameAttempts = $by($this->repo->gameAttemptStats($p, $stageId, true));
        $attendance = $by($this->repo->attendanceStats($p, $stageId, true));
        $points = $by($this->repo->pointsStats($p, $stageId, true));
        $revenue = $by($this->repo->revenueStats($p, $stageId, true));
        $notifications = $by($this->repo->notificationRecipientStats($p, $stageId, true));

        $rows = [];
        foreach ($stages as $stage) {
            $id = $stage['id'];
            $s = $students[$id] ?? [];
            $qa = $quizAttempts[$id] ?? [];
            $ga = $gameAttempts[$id] ?? [];
            $at = $attendance[$id] ?? [];
            $rv = $revenue[$id] ?? [];
            $nt = $notifications[$id] ?? [];

            $graded = $this->i($qa, 'graded_current') + $this->i($ga, 'graded_current');
            $evaluated = $this->i($qa, 'pass_evaluated_current') + $this->i($ga, 'pass_evaluated_current');
            $passed = $this->i($qa, 'passed_current') + $this->i($ga, 'passed_current');
            $pctSum = $this->f($qa, 'pct_sum_current') + $this->f($ga, 'pct_sum_current');
            $evaluatedPrev = $this->i($qa, 'pass_evaluated_previous') + $this->i($ga, 'pass_evaluated_previous');
            $passedPrev = $this->i($qa, 'passed_previous') + $this->i($ga, 'passed_previous');

            $records = $this->i($at, 'records_current');
            $recordsPrev = $this->i($at, 'records_previous');
            $recipients = $this->i($nt, 'recipients_current');

            $row = [
                'stage_id' => $id,
                'name' => $stage['name'],
                'order_number' => $stage['order_number'],
                'students' => [
                    'total' => $this->i($s, 'total'),
                    'active' => $this->i($s, 'active'),
                    'new_current' => $this->i($s, 'new_current'),
                    'new_previous' => $this->i($s, 'new_previous'),
                ],
                'lectures' => [
                    'total' => $this->i($lectures[$id] ?? [], 'total'),
                    'published' => $this->i($lectures[$id] ?? [], 'published'),
                ],
                'quizzes' => [
                    'total' => $this->i($quizzes[$id] ?? [], 'total'),
                    'published' => $this->i($quizzes[$id] ?? [], 'published'),
                ],
                'game_exams' => [
                    'total' => $this->i($games[$id] ?? [], 'total'),
                    'published' => $this->i($games[$id] ?? [], 'published'),
                ],
                'attempts' => [
                    'submitted' => $this->i($qa, 'submitted_current') + $this->i($ga, 'submitted_current'),
                    'graded' => $graded,
                    'pass_evaluated' => $evaluated,
                    'passed' => $passed,
                    'pass_rate' => T::rate($passed, $evaluated),
                    'avg_pct' => $graded > 0 ? round($pctSum / $graded, 2) : null,
                    'pass_evaluated_previous' => $evaluatedPrev,
                    'pass_rate_previous' => T::rate($passedPrev, $evaluatedPrev),
                ],
                'attendance' => [
                    'records' => $records,
                    'present' => $this->i($at, 'present_current'),
                    'late' => $this->i($at, 'late_current'),
                    'absent' => $this->i($at, 'absent_current'),
                    'attendance_rate' => T::rate($this->i($at, 'present_current'), $records),
                    'absence_rate' => T::rate($this->i($at, 'absent_current'), $records),
                    'records_previous' => $recordsPrev,
                    'absence_rate_previous' => T::rate($this->i($at, 'absent_previous'), $recordsPrev),
                ],
                'points' => [
                    'current' => $this->i($points[$id] ?? [], 'points_current'),
                    'total' => $this->i($points[$id] ?? [], 'total_points'),
                ],
                'revenue' => [
                    'current' => $this->f($rv, 'revenue_current'),
                    'previous' => $this->f($rv, 'revenue_previous'),
                    'redemptions_current' => $this->i($rv, 'redemptions_current'),
                    'redemptions_previous' => $this->i($rv, 'redemptions_previous'),
                    'total' => $this->f($rv, 'total_revenue'),
                ],
                'notifications' => [
                    'recipients' => $recipients,
                    'read' => $this->i($nt, 'read_current'),
                    'read_rate' => T::rate($this->i($nt, 'read_current'), $recipients),
                ],
            ];
            $row['evaluation'] = $this->evaluator->evaluate($row);
            $rows[] = $row;
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function attemptBlock(array $r): array
    {
        $out = [
            'submitted' => $this->pair($r, 'submitted'),
            'graded' => $this->pair($r, 'graded'),
            'pass_evaluated' => $this->pair($r, 'pass_evaluated'),
            'passed' => $this->pair($r, 'passed'),
            'students_current' => $this->i($r, 'students_current'),
            'in_progress_now' => $this->i($r, 'in_progress_now'),
            'pending_review_now' => $this->i($r, 'pending_review_now'),
            '_pct_sum' => ['current' => $this->f($r, 'pct_sum_current'), 'previous' => $this->f($r, 'pct_sum_previous')],
        ];
        foreach (['current', 'previous'] as $w) {
            $out['pass_rate'][$w] = T::rate($out['passed'][$w], $out['pass_evaluated'][$w]);
            $out['avg_pct'][$w] = $out['graded'][$w] > 0 ? round($out['_pct_sum'][$w] / $out['graded'][$w], 2) : null;
        }
        return $out;
    }

    private function revenueRow(AnalyticsPeriod $p, ?int $stageId): array
    {
        return $this->memo['revenue'] ??= ($this->repo->revenueStats($p, $stageId)[0] ?? []);
    }

    /** Dense series: every bucket of the window, explicit zeros where no rows exist. */
    private function series(AnalyticsPeriod $p, array $keyed, callable $map): array
    {
        $out = [];
        foreach ($p->bucketKeys() as $key) {
            $out[] = ['bucket' => $key] + $map($keyed[$key] ?? []);
        }
        return $out;
    }

    private function section(string $name, callable $fn): array
    {
        try {
            return ['status' => 'ok', 'data' => $fn()];
        } catch (AppException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Dashboard analytics section failed', [
                'section' => $name,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return ['status' => 'error', 'data' => null, 'error' => 'SECTION_UNAVAILABLE'];
        }
    }

    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->logger->error('Dashboard analytics meta failed', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function pair(array $row, string $prefix): array
    {
        return ['current' => $this->i($row, "{$prefix}_current"), 'previous' => $this->i($row, "{$prefix}_previous")];
    }

    private function i(array $row, string $key): int
    {
        return (int) ($row[$key] ?? 0);
    }

    private function f(array $row, string $key): float
    {
        return round((float) ($row[$key] ?? 0), 2);
    }
}
