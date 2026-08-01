<?php

namespace App\Models;

/**
 * Open-ended by design: a new question type is one new entry here + a
 * `meta` JSON shape + Flutter UI — never a schema migration on
 * quiz_questions (see sql/01_schema.sql comment on the `meta` column).
 */
final class QuestionType
{
    public const MCQ = 'mcq';
    public const TRUE_FALSE = 'true_false';
    public const MULTI_SELECT = 'multi_select';
    public const ESSAY = 'essay';
    public const MATCHING = 'matching';
    public const ORDERING = 'ordering';
    public const FILL_BLANK = 'fill_blank';
    public const NUMERIC = 'numeric';
    public const IMAGE_CHOICE = 'image_choice';
    public const AUDIO = 'audio';
    public const VIDEO = 'video';

    public const ALL = [
        self::MCQ, self::TRUE_FALSE, self::MULTI_SELECT, self::ESSAY,
        self::MATCHING, self::ORDERING, self::FILL_BLANK, self::NUMERIC,
        self::IMAGE_CHOICE, self::AUDIO, self::VIDEO,
    ];

    /** Types that are graded automatically from stored correct choices/answers. */
    public const AUTO_GRADED = [
        self::MCQ, self::TRUE_FALSE, self::MULTI_SELECT,
        self::MATCHING, self::ORDERING, self::FILL_BLANK, self::NUMERIC, self::IMAGE_CHOICE,
    ];

    /** Types that require a teacher to manually score. */
    public const MANUALLY_GRADED = [self::ESSAY, self::AUDIO, self::VIDEO];

    /** Types that store answer choices in quiz_question_choices. */
    public const CHOICE_BASED = [self::MCQ, self::TRUE_FALSE, self::MULTI_SELECT, self::IMAGE_CHOICE];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    public static function isAutoGraded(string $value): bool
    {
        return in_array($value, self::AUTO_GRADED, true);
    }

    public static function isChoiceBased(string $value): bool
    {
        return in_array($value, self::CHOICE_BASED, true);
    }
}
