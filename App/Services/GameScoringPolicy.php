<?php

namespace App\Services;

use App\Config\GameExamConstants as C;
use App\Config\GameExamGrades;
use DateTimeImmutable;

/**
 * Pure business rules for one answer submission and for finalizing an
 * attempt. No I/O — the play service loads rows, calls this, and persists
 * the outcome inside one transaction. Keeping the rules here makes every
 * branch unit-testable without a database.
 *
 * Lifecycle (both question types):
 *   attempt 1 correct → +score, completed
 *   attempt 1 wrong   → 0, retry_pending, gate = answeredAt + retry_seconds
 *   attempt 2 correct → +second_attempt_score (defaults to score), completed
 *   attempt 2 wrong   → -negative_score, completed
 */
final class GameScoringPolicy
{
    /**
     * @param array{score:int|string, second_attempt_score:int|string|null, negative_score:int|string, retry_seconds:int|string} $question
     * @param array{state:string, attempts_used:int|string, retry_available_at:?DateTimeImmutable} $questionState
     * @param int $expectedAttemptNo what the client believed it was submitting (1 or 2)
     * @return array<string,mixed> either ['ok'=>false,'code'=>...] or a full outcome
     */
    public function evaluate(
        array $question,
        array $questionState,
        bool $isCorrect,
        DateTimeImmutable $answeredAt,
        int $expectedAttemptNo
    ): array {
        $state = (string) $questionState['state'];
        $used = (int) $questionState['attempts_used'];

        if ($state === C::Q_COMPLETED || $used >= C::MAX_ATTEMPTS_PER_QUESTION) {
            return ['ok' => false, 'code' => C::CODE_QUESTION_COMPLETED];
        }

        $attemptNo = $used + 1;

        // Another device (or an earlier lost request) already used this slot.
        if ($expectedAttemptNo !== $attemptNo) {
            return ['ok' => false, 'code' => C::CODE_SLOT_TAKEN, 'server_attempt_no' => $attemptNo];
        }

        if ($attemptNo === 2) {
            $gate = $questionState['retry_available_at'] ?? null;
            if ($gate instanceof DateTimeImmutable && $answeredAt < $gate) {
                return [
                    'ok' => false,
                    'code' => C::CODE_RETRY_NOT_READY,
                    'retry_available_at' => $gate,
                ];
            }
        }

        $score = (int) $question['score'];
        $secondScore = $question['second_attempt_score'] === null || $question['second_attempt_score'] === ''
            ? $score
            : (int) $question['second_attempt_score'];
        $penalty = (int) $question['negative_score'];

        if ($attemptNo === 1) {
            if ($isCorrect) {
                return $this->outcome(1, true, $score, $score, 0, C::Q_COMPLETED, C::RESULT_CORRECT_FIRST, null);
            }
            $gate = $answeredAt->modify('+' . max(0, (int) $question['retry_seconds']) . ' seconds');
            return $this->outcome(1, false, 0, 0, 0, C::Q_RETRY_PENDING, null, $gate);
        }

        if ($isCorrect) {
            return $this->outcome(2, true, $secondScore, $secondScore, 0, C::Q_COMPLETED, C::RESULT_CORRECT_SECOND, null);
        }

        return $this->outcome(2, false, -$penalty, 0, $penalty, C::Q_COMPLETED, C::RESULT_WRONG_FINAL, null);
    }

    /**
     * Totals → percentage, pass flag and grade (DEC-7/8/9).
     * @return array{final_points:int, percentage:float, is_passed:bool, grade_key:string, grade_label:string}
     */
    public function finalize(int $maxPoints, int $earned, int $deducted, float $passPercentage): array
    {
        $final = $earned - $deducted;
        $percentage = $maxPoints > 0 ? round(max($final, 0) / $maxPoints * 100, 2) : 0.0;
        $percentage = min($percentage, 100.0);
        $grade = GameExamGrades::resolve($percentage);

        return [
            'final_points' => $final,
            'percentage' => $percentage,
            'is_passed' => $percentage >= $passPercentage,
            'grade_key' => $grade['key'],
            'grade_label' => $grade['label'],
        ];
    }

    /**
     * Result stored for a question still open when the attempt ends (DEC-6):
     * never answered → unanswered; answered wrong once → wrong_final, no penalty.
     */
    public function resultForOpenQuestion(string $state): string
    {
        return $state === C::Q_RETRY_PENDING ? C::RESULT_WRONG_FINAL : C::RESULT_UNANSWERED;
    }

    private function outcome(
        int $attemptNo,
        bool $isCorrect,
        int $delta,
        int $earned,
        int $deducted,
        string $newState,
        ?string $result,
        ?DateTimeImmutable $retryAvailableAt
    ): array {
        return [
            'ok' => true,
            'attempt_no' => $attemptNo,
            'is_correct' => $isCorrect,
            'points_delta' => $delta,
            'earned' => $earned,
            'deducted' => $deducted,
            'new_state' => $newState,
            'result' => $result,
            'retry_available_at' => $retryAvailableAt,
            'completed' => $newState === C::Q_COMPLETED,
        ];
    }
}
