<?php

namespace App\DTO;

use App\Models\QuizStatus;

/**
 * Derives the display-facing availability state from a quiz row + the
 * current time. Never stored — always computed — so it can't drift out of
 * sync with the clock the way a stale cached flag would.
 *
 * States: draft, archived, hidden, upcoming, live, finished
 */
final class QuizAvailabilityDTO
{ 
    public string $state;
    public bool $isVisibleToStudents;

    private function __construct(string $state, bool $isVisibleToStudents)
    {
        $this->state = $state;
        $this->isVisibleToStudents = $isVisibleToStudents;
    }

    public static function fromQuizRow(array $quiz, ?\DateTimeImmutable $now = null): self
    {
        $now = $now ?? new \DateTimeImmutable('now');

        if ($quiz['status'] === QuizStatus::DRAFT) {
            return new self('draft', false);
        }

        if ($quiz['status'] === QuizStatus::ARCHIVED) {
            return new self('archived', false);
        }

        // Published but manually hidden by the teacher.
        if ((int) ($quiz['is_visible'] ?? 1) === 0) {
            return new self('hidden', false);
        }

        $start = self::combine($quiz['start_date'], $quiz['start_time'] ?? '00:00:00');
        $end = self::combine($quiz['end_date'], $quiz['end_time'] ?? '23:59:59');

        if ($now < $start) {
            return new self('upcoming', false);
        }

        if ($now > $end) {
            return new self('finished', false);
        }

        return new self('live', true);
    }

    private static function combine(string $date, string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable(trim($date) . ' ' . trim($time));
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'is_visible_to_students' => $this->isVisibleToStudents,
        ];
    }
}
