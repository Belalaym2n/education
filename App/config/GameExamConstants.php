<?php

namespace App\Config;

/**
 * Single source of truth for every string the Gamified Exams feature
 * stores or returns. Flutter mirrors these in
 * `game_exam_enums.dart` — change both together.
 */
final class GameExamConstants
{
    // Question types
    public const TYPE_CHARACTER = 'character';
    public const TYPE_TRANSFORMATION = 'transformation';
    public const TYPES = [self::TYPE_CHARACTER, self::TYPE_TRANSFORMATION];

    // Exam status (same enum as `quizzes.status`)
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    // Reveal policy (DEC-2)
    public const REVEAL_AFTER_FINAL = 'after_final';
    public const REVEAL_AFTER_FIRST_WRONG = 'after_first_wrong';
    public const REVEAL_POLICIES = [self::REVEAL_AFTER_FINAL, self::REVEAL_AFTER_FIRST_WRONG];

    // Attempt status
    public const ATTEMPT_IN_PROGRESS = 'in_progress';
    public const ATTEMPT_COMPLETED = 'completed';
    public const ATTEMPT_EXPIRED = 'expired';
    public const ATTEMPT_STATUSES = [self::ATTEMPT_IN_PROGRESS, self::ATTEMPT_COMPLETED, self::ATTEMPT_EXPIRED];

    // Per-question state inside an attempt
    public const Q_UNANSWERED = 'unanswered';
    public const Q_RETRY_PENDING = 'retry_pending';
    public const Q_COMPLETED = 'completed';

    // Per-question final result
    public const RESULT_CORRECT_FIRST = 'correct_first';
    public const RESULT_CORRECT_SECOND = 'correct_second';
    public const RESULT_WRONG_FINAL = 'wrong_final';
    public const RESULT_UNANSWERED = 'unanswered';

    // Answer outcome statuses returned to the client
    public const ACTION_APPLIED = 'applied';
    public const ACTION_REPLAYED = 'replayed';
    public const ACTION_REJECTED = 'rejected';

    // Domain rejection codes (returned inside answer/sync results)
    public const CODE_QUESTION_COMPLETED = 'QUESTION_COMPLETED';
    public const CODE_RETRY_NOT_READY = 'RETRY_NOT_READY';
    public const CODE_SLOT_TAKEN = 'SLOT_TAKEN';
    public const CODE_ATTEMPT_EXPIRED = 'ATTEMPT_EXPIRED';
    public const CODE_ATTEMPT_COMPLETED = 'ATTEMPT_COMPLETED';
    public const CODE_ANSWERED_AFTER_EXPIRY = 'ANSWERED_AFTER_EXPIRY';
    public const CODE_INVALID_CHOICE = 'INVALID_CHOICE';
    public const CODE_INVALID_QUESTION = 'INVALID_QUESTION';

    // HTTP-level error codes (thrown as AppException)
    public const ERR_EXAM_HAS_ATTEMPTS = 'EXAM_HAS_ATTEMPTS';
    public const ERR_EXAM_NOT_PUBLISHABLE = 'EXAM_NOT_PUBLISHABLE';
    public const ERR_EXAM_NOT_AVAILABLE = 'EXAM_NOT_AVAILABLE';
    public const ERR_STUDENT_PROFILE_MISSING = 'STUDENT_PROFILE_MISSING';
    public const ERR_SYNC_TOO_LARGE = 'SYNC_TOO_LARGE';
    public const ERR_INVALID_STATE = 'INVALID_STATE';

    // Ledger
    public const LEDGER_SOURCE_GAME_ANSWER = 'game_answer';

    // Limits
    public const MAX_QUESTIONS = 100;
    public const MIN_CHOICES = 2;
    public const MAX_CHOICES = 6;
    public const SYNC_MAX_ACTIONS = 50;
    public const MAX_ATTEMPTS_PER_QUESTION = 2;
    public const MAX_PAGE_LIMIT = 100;
    public const DEFAULT_PAGE_LIMIT = 20;
    public const TOP_STUDENTS_DEFAULT = 5;
    public const TOP_STUDENTS_MAX = 10;
    public const RECENT_DAYS = 7;
    /** Tolerated device-clock skew before `started_at` for offline actions. */
    public const CLIENT_CLOCK_SKEW_SECONDS = 60;

    /** DEC-5: set to an int (e.g. 0) to stop student points going below it. */
    public const STUDENT_POINTS_FLOOR = null;

    public const DUPLICATE_SUFFIX = ' (نسخة)';

    private function __construct()
    {
    }
}
