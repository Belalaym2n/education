<?php

namespace App\Services;

use App\Support\AnalyticsThresholds as T;

/**
 * Turns already-computed dashboard sections into a short list of alerts.
 * Each insight is a code + the numbers that triggered it; the client
 * renders the wording. Rules only fire when both windows have enough data.
 */
final class DashboardInsightBuilder
{
    /**
     * @param array<string, array{status:string, data:?array}> $sections
     * @return array<int, array>
     */
    public function build(array $sections): array
    {
        $out = [];
        $data = fn(string $k) => ($sections[$k]['status'] ?? 'error') === 'ok' ? $sections[$k]['data'] : null;

        foreach ($sections as $key => $section) {
            if (($section['status'] ?? 'ok') !== 'ok') {
                $out[] = $this->make('section_unavailable', 'warning', ['section' => $key]);
            }
        }

        if ($revenue = $data('revenue')) {
            $cur = $revenue['revenue']['current'];
            $prev = $revenue['revenue']['previous'];
            $growth = T::growthPct($cur, $prev);
            if ($revenue['redemptions']['previous'] >= T::MIN_REVENUE_BASELINE_REDEMPTIONS
                && $growth !== null && $growth <= T::REVENUE_DROP_PCT) {
                $out[] = $this->make('revenue_drop', 'warning', [
                    'current' => $cur, 'previous' => $prev, 'growth_pct' => $growth, 'threshold_pct' => T::REVENUE_DROP_PCT,
                ]);
            }
        }

        if ($students = $data('students')) {
            $cur = $students['new']['current'];
            $prev = $students['new']['previous'];
            $growth = T::growthPct($cur, $prev);
            if ($prev >= T::MIN_ENROLLMENT_BASELINE && $growth !== null && $growth <= T::ENROLLMENT_DROP_PCT) {
                $out[] = $this->make('enrollment_drop', 'warning', [
                    'current' => $cur, 'previous' => $prev, 'growth_pct' => $growth, 'threshold_pct' => T::ENROLLMENT_DROP_PCT,
                ]);
            }
        }

        if ($assess = $data('assessments')) {
            $c = $assess['combined'];
            if ($c['pass_evaluated']['current'] >= T::MIN_GRADED_ATTEMPTS
                && $c['pass_evaluated']['previous'] >= T::MIN_GRADED_ATTEMPTS
                && $c['pass_rate']['current'] !== null && $c['pass_rate']['previous'] !== null) {
                $drop = round($c['pass_rate']['previous'] - $c['pass_rate']['current'], 2);
                if ($drop >= T::PASS_RATE_DROP_POINTS) {
                    $out[] = $this->make('pass_rate_drop', 'critical', [
                        'current' => $c['pass_rate']['current'], 'previous' => $c['pass_rate']['previous'],
                        'drop_points' => $drop, 'threshold_points' => T::PASS_RATE_DROP_POINTS,
                    ]);
                }
            }
            $pending = $assess['quiz']['pending_review_now'];
            if ($pending > 0) {
                $out[] = $this->make('pending_essay_reviews', 'info', ['attempts' => $pending]);
            }
        }

        if ($att = $data('attendance')) {
            if ($att['records']['current'] >= T::MIN_ATTENDANCE_RECORDS
                && $att['records']['previous'] >= T::MIN_ATTENDANCE_RECORDS) {
                $rise = round(($att['absence_rate']['current'] ?? 0) - ($att['absence_rate']['previous'] ?? 0), 2);
                if ($rise >= T::ABSENCE_RISE_POINTS) {
                    $out[] = $this->make('absence_rise', 'warning', [
                        'current' => $att['absence_rate']['current'], 'previous' => $att['absence_rate']['previous'],
                        'rise_points' => $rise, 'threshold_points' => T::ABSENCE_RISE_POINTS,
                    ]);
                }
            }
        }

        if ($grades = $data('grades')) {
            foreach ($grades['rows'] as $g) {
                $ctx = ['stage_id' => $g['stage_id'], 'stage_name' => $g['name']];
                $ev = $g['evaluation'];
                if ($ev['academic']['level'] === 'needs_attention') {
                    $out[] = $this->make('grade_low_pass_rate', 'warning', $ev['academic']['evidence'] + ['threshold' => T::WEAK_PASS_RATE], $ctx);
                }
                if ($ev['attendance']['level'] === 'needs_attention') {
                    $out[] = $this->make('grade_high_absence', 'warning', $ev['attendance']['evidence'] + ['threshold' => T::HIGH_ABSENCE_RATE], $ctx);
                }
                if ($g['students']['total'] > 0 && $g['lectures']['published'] === 0) {
                    $out[] = $this->make('grade_no_published_lectures', 'info', ['students' => $g['students']['total']], $ctx);
                }
                if ($ev['assessment_activity']['level'] === 'no_assessments') {
                    $out[] = $this->make('grade_no_assessments', 'info', $ev['assessment_activity']['evidence'], $ctx);
                }
            }
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $out;
    }

    private function make(string $code, string $severity, array $evidence, array $ctx = []): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'stage_id' => $ctx['stage_id'] ?? null,
            'stage_name' => $ctx['stage_name'] ?? null,
            'evidence' => $evidence,
        ];
    }
}
