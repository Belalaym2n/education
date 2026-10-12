<?php

namespace App\Services;

use App\Support\AnalyticsThresholds as T;

/**
 * Explainable, per-dimension grade evaluation.
 *
 * Deliberately NO combined score: academic outcomes, attendance, enrollment,
 * money and assessment activity are reported side by side. A grade is never
 * labelled weak because it is small or earns less. Every level carries the
 * evidence it was derived from, and small samples are "insufficient_data".
 */
final class GradePerformanceEvaluator
{
    /**
     * @param array $row grade row as built by DashboardAnalyticsService::gradeRows()
     */
    public function evaluate(array $row): array
    {
        return [
            'academic' => $this->academic($row['attempts']),
            'attendance' => $this->attendance($row['attendance']),
            'enrollment' => $this->enrollment($row['students']),
            'financial' => $this->financial($row['revenue']),
            'assessment_activity' => $this->assessmentActivity($row),
        ];
    }

    public function academic(array $a): array
    {
        $evidence = [
            'graded' => $a['graded'],
            'pass_rate' => $a['pass_rate'],
            'avg_pct' => $a['avg_pct'],
        ];
        if ($a['graded'] < T::MIN_GRADED_ATTEMPTS || $a['avg_pct'] === null) {
            return ['level' => 'insufficient_data', 'evidence' => $evidence];
        }
        $passRate = $a['pass_rate'];
        if ($passRate !== null && $passRate < T::WEAK_PASS_RATE) {
            $level = 'needs_attention';
        } elseif (($passRate === null || $passRate >= T::STRONG_PASS_RATE) && $a['avg_pct'] >= T::STRONG_AVG_SCORE) {
            $level = 'strong';
        } else {
            $level = 'steady';
        }
        return ['level' => $level, 'evidence' => $evidence];
    }

    public function attendance(array $a): array
    {
        $evidence = [
            'records' => $a['records'],
            'attendance_rate' => $a['attendance_rate'],
            'absence_rate' => $a['absence_rate'],
        ];
        if ($a['records'] < T::MIN_ATTENDANCE_RECORDS) {
            return ['level' => 'insufficient_data', 'evidence' => $evidence];
        }
        if ($a['absence_rate'] >= T::HIGH_ABSENCE_RATE) {
            $level = 'needs_attention';
        } elseif ($a['attendance_rate'] >= T::STRONG_ATTENDANCE_RATE) {
            $level = 'strong';
        } else {
            $level = 'steady';
        }
        return ['level' => $level, 'evidence' => $evidence];
    }

    public function enrollment(array $s): array
    {
        $growth = T::growthPct($s['new_current'], $s['new_previous']);
        $evidence = ['current' => $s['new_current'], 'previous' => $s['new_previous'], 'growth_pct' => $growth];
        if ($s['new_previous'] < T::MIN_ENROLLMENT_BASELINE) {
            return ['level' => 'insufficient_data', 'evidence' => $evidence];
        }
        return ['level' => $this->trend($growth), 'evidence' => $evidence];
    }

    public function financial(array $r): array
    {
        $growth = T::growthPct($r['current'], $r['previous']);
        $evidence = [
            'current' => $r['current'],
            'previous' => $r['previous'],
            'redemptions_previous' => $r['redemptions_previous'],
            'growth_pct' => $growth,
        ];
        if ($r['redemptions_previous'] < T::MIN_REVENUE_BASELINE_REDEMPTIONS) {
            return ['level' => 'insufficient_data', 'evidence' => $evidence];
        }
        return ['level' => $this->trend($growth), 'evidence' => $evidence];
    }

    public function assessmentActivity(array $row): array
    {
        $published = $row['quizzes']['published'] + $row['game_exams']['published'];
        $submitted = $row['attempts']['submitted'];
        $evidence = ['published_assessments' => $published, 'submitted' => $submitted, 'students' => $row['students']['total']];

        if ($row['students']['total'] === 0) {
            $level = 'no_students';
        } elseif ($published === 0) {
            $level = 'no_assessments';
        } elseif ($submitted === 0) {
            $level = 'inactive';
        } else {
            $level = 'active';
        }
        return ['level' => $level, 'evidence' => $evidence];
    }

    private function trend(?float $growth): string
    {
        if ($growth === null) {
            return 'insufficient_data';
        }
        if ($growth >= T::GROWTH_UP_PCT) {
            return 'growing';
        }
        if ($growth <= T::GROWTH_DOWN_PCT) {
            return 'declining';
        }
        return 'steady';
    }
}
