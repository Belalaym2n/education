<?php

namespace App\Config;

/**
 * Grade bands (DEC-9). The ONLY place grade thresholds live — the API
 * returns `grade_key` + `grade_label`, Flutter never computes a grade.
 * Bands are ordered high → low; the first band whose `min` is <= the
 * percentage wins.
 */
final class GameExamGrades
{
    public const BANDS = [
        ['key' => 'excellent_plus', 'label' => 'ممتاز جدًا', 'min' => 95.0],
        ['key' => 'excellent',      'label' => 'ممتاز',      'min' => 85.0],
        ['key' => 'very_good',      'label' => 'جيد جدًا',   'min' => 75.0],
        ['key' => 'good',           'label' => 'جيد',        'min' => 65.0],
        ['key' => 'pass',           'label' => 'مقبول',      'min' => 50.0],
        ['key' => 'weak',           'label' => 'ضعيف',       'min' => 0.0],
    ];

    /** @return array{key:string,label:string} */
    public static function resolve(float $percentage): array
    {
        foreach (self::BANDS as $band) {
            if ($percentage >= $band['min']) {
                return ['key' => $band['key'], 'label' => $band['label']];
            }
        }
        $last = self::BANDS[count(self::BANDS) - 1];
        return ['key' => $last['key'], 'label' => $last['label']];
    }

    private function __construct()
    {
    }
}
