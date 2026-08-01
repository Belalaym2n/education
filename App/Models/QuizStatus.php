<?php

namespace App\Models;

/**
 * Teacher-controlled lifecycle state, stored on `quizzes.status`.
 * Distinct from "availability" (upcoming/live/finished), which is derived
 * from the schedule at read time — see QuizAvailabilityDTO.
 */
final class QuizStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    public const ALL = [self::DRAFT, self::PUBLISHED, self::ARCHIVED];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
