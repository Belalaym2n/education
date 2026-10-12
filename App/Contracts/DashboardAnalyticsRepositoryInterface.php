<?php

namespace App\Contracts;

use App\Support\AnalyticsPeriod;

/**
 * Read-only, aggregate-only data access for the teacher dashboard.
 *
 * Every "stats" method returns one row when $byStage is false, or one row
 * per stage (with a `stage_id` column) when $byStage is true. Counts for the
 * current and previous windows are computed in the same scan.
 */
interface DashboardAnalyticsRepositoryInterface
{
    /** DB-session wall-clock "now" — the clock every local timestamp column uses. */
    public function localNow(): string;

    /** @return array<int, array{id:int,name:string,order_number:int}> */
    public function stages(): array;

    public function stageExists(int $stageId): bool;

    public function viewerName(int $userId): ?string;

    public function studentStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function revenueStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function couponStats(AnalyticsPeriod $p, ?int $stageId): array;

    public function lectureStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function quizStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function gameExamStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function quizAttemptStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function gameAttemptStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function attendanceStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function pointsStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function notificationStats(AnalyticsPeriod $p): array;

    public function notificationRecipientStats(AnalyticsPeriod $p, ?int $stageId, bool $byStage = false): array;

    public function dispatchStats(AnalyticsPeriod $p): array;

    /** @return array<string, array> bucket => aggregated values */
    public function studentSeries(AnalyticsPeriod $p, ?int $stageId): array;

    public function revenueSeries(AnalyticsPeriod $p, ?int $stageId): array;

    public function quizAttemptSeries(AnalyticsPeriod $p, ?int $stageId): array;

    public function gameAttemptSeries(AnalyticsPeriod $p, ?int $stageId): array;

    public function attendanceSeries(AnalyticsPeriod $p, ?int $stageId): array;

    public function pointsSeries(AnalyticsPeriod $p, ?int $stageId): array;

    public function notificationSeries(AnalyticsPeriod $p, ?int $stageId): array;

    /** @return array<string,int> score band => graded attempts in the current window */
    public function scoreDistribution(AnalyticsPeriod $p, ?int $stageId): array;

    public function topLectures(AnalyticsPeriod $p, ?int $stageId, int $limit): array;

    public function recentActivity(?int $stageId, int $limit): array;
}
