<?php
/**
 * Dependency-free tests for the teacher dashboard analytics.
 * The project has no PHPUnit setup, so this runs with plain PHP:
 *
 *   php tests/DashboardAnalyticsTest.php
 *
 * Exit code 0 = all passed. No database is touched: the service is tested
 * against an in-memory fake repository.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\DashboardAnalyticsRepositoryInterface;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Services\DashboardAnalyticsService;
use App\Services\DashboardInsightBuilder;
use App\Services\GradePerformanceEvaluator;
use App\Support\AnalyticsPeriod;
use App\Support\AnalyticsThresholds as T;

$failures = 0;
$count = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}
function eq(string $name, $expected, $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function throwsValidation(string $name, callable $fn): void
{
    try {
        $fn();
        check($name, false, 'no exception');
    } catch (ValidationException $e) {
        check($name, true);
    }
}

// ---------------------------------------------------------------------
// AnalyticsPeriod
// ---------------------------------------------------------------------
$p = AnalyticsPeriod::resolve('month', null, null, '2026-10-12 11:30:00');
eq('month from', '2026-10-01 00:00:00', $p->from);
eq('month to = now (partial)', '2026-10-12 11:30:00', $p->to);
eq('month partial flag', true, $p->isPartial);
eq('month prev from', '2026-09-01 00:00:00', $p->prevFrom);
eq('month prev = same elapsed', '2026-09-12 11:30:00', $p->prevTo);
eq('month granularity', 'day', $p->granularity);
eq('month buckets = days in month', 31, count($p->bucketKeys()));

// Elapsed part of a long month must not spill into the current month.
$p = AnalyticsPeriod::resolve('month', null, null, '2026-03-31 20:00:00');
eq('march 31 prev capped at month start', '2026-03-01 00:00:00', $p->prevTo);
eq('march 31 prev from', '2026-02-01 00:00:00', $p->prevFrom);

$p = AnalyticsPeriod::resolve('today', null, null, '2026-10-12 09:15:00');
eq('today prev from', '2026-10-11 00:00:00', $p->prevFrom);
eq('today prev to', '2026-10-11 09:15:00', $p->prevTo);
eq('today hourly buckets', 24, count($p->bucketKeys()));

$p = AnalyticsPeriod::resolve('week', null, null, '2026-10-14 10:00:00'); // Wednesday
eq('week starts monday', '2026-10-12 00:00:00', $p->from);
eq('week prev from', '2026-10-05 00:00:00', $p->prevFrom);
eq('week prev to', '2026-10-07 10:00:00', $p->prevTo);

$p = AnalyticsPeriod::resolve('year', null, null, '2026-10-12 00:00:00');
eq('year prev', ['2025-01-01 00:00:00', '2025-10-12 00:00:00'], [$p->prevFrom, $p->prevTo]);
eq('year monthly buckets', 12, count($p->bucketKeys()));

$p = AnalyticsPeriod::resolve('custom', '2026-07-01', '2026-07-10', '2026-10-12 00:00:00');
eq('custom complete', false, $p->isPartial);
eq('custom to exclusive', '2026-07-11 00:00:00', $p->to);
eq('custom prev immediately before', ['2026-06-21 00:00:00', '2026-07-01 00:00:00'], [$p->prevFrom, $p->prevTo]);
eq('custom daily granularity', 'day', $p->granularity);

$p = AnalyticsPeriod::resolve('custom', '2026-01-01', '2026-06-30', '2026-10-12 00:00:00');
eq('long custom monthly', 'month', $p->granularity);

throwsValidation('bad preset', fn() => AnalyticsPeriod::resolve('decade', null, null, '2026-10-12 00:00:00'));
throwsValidation('custom needs dates', fn() => AnalyticsPeriod::resolve('custom', null, null, '2026-10-12 00:00:00'));
throwsValidation('invalid calendar date', fn() => AnalyticsPeriod::resolve('custom', '2026-02-30', '2026-03-01', '2026-10-12 00:00:00'));
throwsValidation('reversed range', fn() => AnalyticsPeriod::resolve('custom', '2026-05-01', '2026-04-01', '2026-10-12 00:00:00'));
throwsValidation('future start', fn() => AnalyticsPeriod::resolve('custom', '2027-01-01', '2027-01-02', '2026-10-12 00:00:00'));
throwsValidation('range too long', fn() => AnalyticsPeriod::resolve('custom', '2020-01-01', '2026-01-01', '2026-10-12 00:00:00'));
throwsValidation('sql-ish date', fn() => AnalyticsPeriod::resolve('custom', "2026-01-01' OR 1=1", '2026-01-02', '2026-10-12 00:00:00'));

// ---------------------------------------------------------------------
// Growth / rates
// ---------------------------------------------------------------------
eq('growth normal', 50.0, T::growthPct(150, 100));
eq('growth decline', -25.0, T::growthPct(75, 100));
eq('growth zero baseline is null', null, T::growthPct(10, 0));
eq('rate zero denominator is null', null, T::rate(0, 0));
eq('rate', 33.33, T::rate(1, 3));

// ---------------------------------------------------------------------
// GradePerformanceEvaluator
// ---------------------------------------------------------------------
$ev = new GradePerformanceEvaluator();
eq('academic insufficient', 'insufficient_data', $ev->academic(['graded' => 4, 'pass_rate' => 100.0, 'avg_pct' => 100.0])['level']);
eq('academic strong', 'strong', $ev->academic(['graded' => 10, 'pass_rate' => 80.0, 'avg_pct' => 75.0])['level']);
eq('academic weak', 'needs_attention', $ev->academic(['graded' => 10, 'pass_rate' => 40.0, 'avg_pct' => 45.0])['level']);
eq('academic steady', 'steady', $ev->academic(['graded' => 10, 'pass_rate' => 60.0, 'avg_pct' => 65.0])['level']);
eq('attendance insufficient', 'insufficient_data', $ev->attendance(['records' => 3, 'attendance_rate' => 0.0, 'absence_rate' => 100.0])['level']);
eq('attendance high absence', 'needs_attention', $ev->attendance(['records' => 20, 'attendance_rate' => 70.0, 'absence_rate' => 25.0])['level']);
eq('attendance strong', 'strong', $ev->attendance(['records' => 20, 'attendance_rate' => 90.0, 'absence_rate' => 5.0])['level']);
eq('enrollment no baseline', 'insufficient_data', $ev->enrollment(['new_current' => 9, 'new_previous' => 0])['level']);
eq('enrollment growing', 'growing', $ev->enrollment(['new_current' => 12, 'new_previous' => 5])['level']);
eq('enrollment declining', 'declining', $ev->enrollment(['new_current' => 2, 'new_previous' => 5])['level']);
// Revenue never feeds the academic label.
$row = [
    'students' => ['total' => 3, 'active' => 3, 'new_current' => 0, 'new_previous' => 0],
    'quizzes' => ['published' => 1], 'game_exams' => ['published' => 0],
    'attempts' => ['submitted' => 10, 'graded' => 10, 'pass_rate' => 90.0, 'avg_pct' => 88.0],
    'attendance' => ['records' => 0, 'attendance_rate' => null, 'absence_rate' => null],
    'revenue' => ['current' => 0.0, 'previous' => 500.0, 'redemptions_previous' => 10],
];
$res = $ev->evaluate($row);
eq('small low-revenue grade still academically strong', 'strong', $res['academic']['level']);
eq('financial declining kept separate', 'declining', $res['financial']['level']);
$row['quizzes']['published'] = 0;
eq('no assessments flagged', 'no_assessments', $ev->assessmentActivity($row)['level']);

// ---------------------------------------------------------------------
// Service with a fake repository
// ---------------------------------------------------------------------
final class FakeAnalyticsRepo implements DashboardAnalyticsRepositoryInterface
{
    public array $fail = [];
    private function f(string $m): void { if (in_array($m, $this->fail, true)) throw new \RuntimeException("boom $m"); }
    public function localNow(): string { return '2026-10-12 12:00:00'; }
    public function stages(): array { return [['id' => 1, 'name' => 'G1', 'order_number' => 1], ['id' => 2, 'name' => 'G2', 'order_number' => 2]]; }
    public function stageExists(int $stageId): bool { return in_array($stageId, [1, 2], true); }
    public function viewerName(int $userId): ?string { return 'Teacher'; }
    public function studentStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array {
        $this->f('students');
        return $by ? [['stage_id' => 1, 'total' => 4, 'active' => 4, 'new_current' => 2, 'new_previous' => 1], ['stage_id' => 2, 'total' => 6, 'active' => 5, 'new_current' => 1, 'new_previous' => 0]]
                   : [['total' => 10, 'active' => 9, 'new_current' => 3, 'new_previous' => 1]];
    }
    public function revenueStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array {
        $this->f('revenue');
        return $by ? [['stage_id' => 1, 'total_revenue' => 100, 'revenue_current' => 50, 'redemptions_current' => 2, 'revenue_previous' => 0, 'redemptions_previous' => 0]]
                   : [['total_revenue' => '250.50', 'total_redemptions' => 5, 'revenue_current' => '100.25', 'redemptions_current' => 2, 'students_current' => 2, 'revenue_previous' => '0', 'redemptions_previous' => 0]];
    }
    public function couponStats(AnalyticsPeriod $p, ?int $s): array { return ['total' => 4, 'active' => 2, 'total_uses' => 5, 'total_capacity' => 0]; }
    public function lectureStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array { return $by ? [['stage_id' => 1, 'total' => 3, 'published' => 2]] : [['total' => 3, 'published' => 2, 'free' => 1]]; }
    public function quizStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array { return $by ? [] : [['total' => 2, 'published' => 1]]; }
    public function gameExamStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array { return $by ? [] : [['total' => 1, 'published' => 1]]; }
    public function quizAttemptStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array {
        // quiz: 2 attempts averaging 90%  |  game: 8 attempts averaging 40%
        return $by ? [] : [['graded_current' => 2, 'pass_evaluated_current' => 2, 'passed_current' => 2, 'pct_sum_current' => 180, 'pending_review_now' => 1]];
    }
    public function gameAttemptStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array {
        return $by ? [] : [['graded_current' => 8, 'pass_evaluated_current' => 8, 'passed_current' => 0, 'pct_sum_current' => 320]];
    }
    public function attendanceStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array {
        $this->f('attendance');
        return $by ? [] : [['records_current' => 10, 'present_current' => 7, 'late_current' => 1, 'absent_current' => 2]];
    }
    public function pointsStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array { return $by ? [] : [['total_points' => 30, 'points_current' => 0, 'students_current' => 0]]; }
    public function notificationStats(AnalyticsPeriod $p): array { return ['total' => 3]; }
    public function notificationRecipientStats(AnalyticsPeriod $p, ?int $s, bool $by = false): array { return $by ? [] : [['recipients_current' => 0, 'read_current' => 0]]; }
    public function dispatchStats(AnalyticsPeriod $p): array { return ['total_logs' => 0]; }
    public function studentSeries(AnalyticsPeriod $p, ?int $s): array { return ['2026-10-05' => ['new_students' => 2]]; }
    public function revenueSeries(AnalyticsPeriod $p, ?int $s): array { return []; }
    public function quizAttemptSeries(AnalyticsPeriod $p, ?int $s): array { return []; }
    public function gameAttemptSeries(AnalyticsPeriod $p, ?int $s): array { return []; }
    public function attendanceSeries(AnalyticsPeriod $p, ?int $s): array { return []; }
    public function pointsSeries(AnalyticsPeriod $p, ?int $s): array { return []; }
    public function notificationSeries(AnalyticsPeriod $p, ?int $s): array { return []; }
    public function scoreDistribution(AnalyticsPeriod $p, ?int $s): array { return ['0-49' => 8, '85-100' => 2]; }
    public function topLectures(AnalyticsPeriod $p, ?int $s, int $limit): array { return []; }
    public function recentActivity(?int $s, int $limit): array { return [['type' => 'coupon_redeemed', 'occurred_at' => '2026-10-12 10:00:00', 'actor' => 'A', 'stage_name' => 'G1', 'subject' => 'L', 'amount' => '23.00']]; }
}

$logDir = sys_get_temp_dir() . '/dash_analytics_test_logs';
@mkdir($logDir);
$repo = new FakeAnalyticsRepo();
$svc = new DashboardAnalyticsService($repo, new GradePerformanceEvaluator(), new DashboardInsightBuilder(), new Logger($logDir));
$out = $svc->overview(1, 'month', null, null, null);
$s = $out['sections'];

eq('students ok', 'ok', $s['students']['status']);
eq('inactive derived', 1, $s['students']['data']['inactive']);
eq('dense series fills month', 31, count($s['students']['data']['series']));
eq('series zero-filled bucket', 0, $s['students']['data']['series'][0]['new_students']);
eq('series value placed', 2, $s['students']['data']['series'][4]['new_students']);
eq('revenue decimal parsing', 100.25, $s['revenue']['data']['revenue']['current']);
eq('avg per redemption', 50.13, $s['revenue']['data']['avg_per_redemption_current']);
eq('coupon usage rate null when no capacity', null, $s['coupons']['data']['usage_rate']);
// Attempt-weighted: (180 + 320) / 10 = 50, not (90 + 40) / 2 = 65.
eq('combined avg is attempt-weighted', 50.0, $s['assessments']['data']['combined']['avg_pct']['current']);
eq('combined pass rate', 20.0, $s['assessments']['data']['combined']['pass_rate']['current']);
eq('no previous attempts => null pass rate', null, $s['assessments']['data']['combined']['pass_rate']['previous']);
eq('score bands always complete', 5, count($s['assessments']['data']['score_distribution']));
eq('attendance rate = present/records', 70.0, $s['attendance']['data']['attendance_rate']['current']);
eq('absence rate', 20.0, $s['attendance']['data']['absence_rate']['current']);
eq('delivery not claimed without logs', false, $s['notifications']['data']['delivery']['tracked']);
eq('grade rows for every stage', 2, count($s['grades']['data']['rows']));
eq('grade without data has zero students', 4, $s['grades']['data']['rows'][0]['students']['total']);
$codes = array_column($s['insights']['data']['items'], 'code');
check('pending reviews insight', in_array('pending_essay_reviews', $codes, true));

// Partial failure: the failing section is an error, never zeros, others still render.
$repo->fail = ['attendance'];
$out = $svc->overview(1, 'month', null, null, null);
eq('failed section marked error', 'error', $out['sections']['attendance']['status']);
eq('failed section has no data', null, $out['sections']['attendance']['data']);
eq('other sections still ok', 'ok', $out['sections']['revenue']['status']);
eq('grades fail too (uses attendance)', 'error', $out['sections']['grades']['status']);
check('section_unavailable insight', in_array('section_unavailable', array_column($out['sections']['insights']['data']['items'], 'code'), true));
$repo->fail = [];

throwsValidation('unknown stage rejected', fn() => $svc->overview(1, 'month', null, null, 99));
eq('activity amount parsed', 23.0, $svc->activity(null, 10)[0]['amount']);

// Insight rules
$ib = new DashboardInsightBuilder();
$items = $ib->build([
    'revenue' => ['status' => 'ok', 'data' => ['revenue' => ['current' => 50.0, 'previous' => 100.0], 'redemptions' => ['current' => 2, 'previous' => 4]]],
    'assessments' => ['status' => 'ok', 'data' => [
        'quiz' => ['pending_review_now' => 0],
        'combined' => ['pass_evaluated' => ['current' => 10, 'previous' => 10], 'pass_rate' => ['current' => 50.0, 'previous' => 70.0]],
    ]],
    'attendance' => ['status' => 'ok', 'data' => ['records' => ['current' => 20, 'previous' => 20], 'absence_rate' => ['current' => 30.0, 'previous' => 10.0]]],
]);
$codes = array_column($items, 'code');
check('revenue drop fires', in_array('revenue_drop', $codes, true));
check('pass rate drop fires', in_array('pass_rate_drop', $codes, true));
check('absence rise fires', in_array('absence_rise', $codes, true));
eq('critical sorted first', 'pass_rate_drop', $items[0]['code']);
$items = $ib->build([
    'revenue' => ['status' => 'ok', 'data' => ['revenue' => ['current' => 0.0, 'previous' => 100.0], 'redemptions' => ['current' => 0, 'previous' => 1]]],
]);
eq('no revenue alert on tiny baseline', [], $items);

echo $failures === 0 ? "OK  $count assertions passed\n" : "$failures of $count assertions FAILED\n";
exit($failures === 0 ? 0 : 1);
