<?php

namespace App\Support;

/**
 * Every threshold the dashboard uses to flag a grade or raise an insight.
 * Kept in one place so the rules are explicit, testable and returned to the
 * client verbatim (the dashboard explains each alert with these numbers).
 *
 * These are heuristics, not business facts — the UI labels them as such.
 */
final class AnalyticsThresholds
{
    /** Minimum graded exam attempts before a pass rate / average is evaluated. */
    public const MIN_GRADED_ATTEMPTS = 5;
    /** Minimum attendance records before an attendance/absence rate is evaluated. */
    public const MIN_ATTENDANCE_RECORDS = 10;
    /** Minimum previous-period new students before enrollment growth is evaluated. */
    public const MIN_ENROLLMENT_BASELINE = 3;
    /** Minimum previous-period redemptions before a revenue change is evaluated. */
    public const MIN_REVENUE_BASELINE_REDEMPTIONS = 3;

    public const STRONG_PASS_RATE = 75.0;
    public const STRONG_AVG_SCORE = 70.0;
    public const WEAK_PASS_RATE = 50.0;

    public const STRONG_ATTENDANCE_RATE = 85.0;
    public const HIGH_ABSENCE_RATE = 20.0;

    public const GROWTH_UP_PCT = 20.0;
    public const GROWTH_DOWN_PCT = -30.0;

    /** Percentage-point drop in pass rate (vs previous period) that raises an alert. */
    public const PASS_RATE_DROP_POINTS = 10.0;
    /** Percentage-point rise in absence rate (vs previous period) that raises an alert. */
    public const ABSENCE_RISE_POINTS = 10.0;
    /** Revenue change (%) vs previous period that raises an alert. */
    public const REVENUE_DROP_PCT = -20.0;
    /** New-student change (%) vs previous period that raises an alert. */
    public const ENROLLMENT_DROP_PCT = -50.0;

    public static function toArray(): array
    {
        return [
            'min_graded_attempts' => self::MIN_GRADED_ATTEMPTS,
            'min_attendance_records' => self::MIN_ATTENDANCE_RECORDS,
            'min_enrollment_baseline' => self::MIN_ENROLLMENT_BASELINE,
            'min_revenue_baseline_redemptions' => self::MIN_REVENUE_BASELINE_REDEMPTIONS,
            'strong_pass_rate' => self::STRONG_PASS_RATE,
            'strong_avg_score' => self::STRONG_AVG_SCORE,
            'weak_pass_rate' => self::WEAK_PASS_RATE,
            'strong_attendance_rate' => self::STRONG_ATTENDANCE_RATE,
            'high_absence_rate' => self::HIGH_ABSENCE_RATE,
            'growth_up_pct' => self::GROWTH_UP_PCT,
            'growth_down_pct' => self::GROWTH_DOWN_PCT,
            'pass_rate_drop_points' => self::PASS_RATE_DROP_POINTS,
            'absence_rise_points' => self::ABSENCE_RISE_POINTS,
            'revenue_drop_pct' => self::REVENUE_DROP_PCT,
            'enrollment_drop_pct' => self::ENROLLMENT_DROP_PCT,
        ];
    }

    /** ((current - previous) / previous) * 100, or null when there is no baseline. */
    public static function growthPct(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    /** Safe percentage: null when the denominator is zero (never 0% by default). */
    public static function rate(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }
}
