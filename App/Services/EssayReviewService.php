<?php

namespace App\Services;

use App\Contracts\AttemptRepositoryInterface;
use App\Contracts\AttemptAnswerRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;

class EssayReviewService
{
    private AttemptRepositoryInterface $attemptRepo;
    private AttemptAnswerRepositoryInterface $answerRepo;
    private Database $db;
    private Logger $logger;

    public function __construct(
        AttemptRepositoryInterface $attemptRepo,
        AttemptAnswerRepositoryInterface $answerRepo,
        Database $db,
        Logger $logger
    ) {
        $this->attemptRepo = $attemptRepo;
        $this->answerRepo = $answerRepo;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Fetches all essays pending review for a quiz, grouped by attempt +
     * student for the teacher's worklist UI.
     */
    public function getPendingEssays(int $quizId): array
    {
        $essays = $this->answerRepo->pendingReviewForQuiz($quizId);

        if (empty($essays)) {
            return [];
        }

        // Group by attempt so the teacher can review all of a student's essays at once.
        $grouped = [];
        foreach ($essays as $essay) {
            $attemptId = $essay['attempt_id'];
            if (!isset($grouped[$attemptId])) {
                $grouped[$attemptId] = [
                    'attempt_id' => $attemptId,
                    'student_id' => $essay['student_id'],
                    'student_name' => $essay['student_name'],
                    'student_id_display' => $essay['student_id_display'],
                    'quiz_name' => $essay['quiz_name'],
                    'essays' => [],
                ];
            }
            $grouped[$attemptId]['essays'][] = $essay;
        }

        return array_values($grouped);
    }

    /**
     * Scores a single essay answer with marks and optional teacher notes.
     * Does not publish the score — that's done in bulk via publishReviews().
     */
    public function scoreEssay(int $answerId, float $marksAwarded, ?string $teacherNotes, int $reviewerId): array
    {
        $answer = $this->answerRepo->find($answerId);
        if (!$answer) {
            throw new NotFoundException('Answer');
        }

        if ($answer['type'] !== 'essay') {
            throw new ValidationException(['type' => 'Only essay answers can be manually scored.']);
        }

        if ($marksAwarded < 0) {
            throw new ValidationException(['marks_awarded' => 'Marks cannot be negative.']);
        }

        if ($marksAwarded > (float) $answer['marks']) {
throw new ValidationException([
    'marks_awarded' => "Marks awarded cannot exceed the question's total marks (" .
        $answer['marks'] .
        ")."
]);        }

        $update = [
            'marks_awarded' => $marksAwarded,
            'is_correct' => $marksAwarded >= ((float) $answer['marks'] * 0.5) ? 1 : 0, // > 50% = correct
        ];

        if ($teacherNotes !== null) {
            $update['teacher_notes'] = $teacherNotes;
        }

        $this->answerRepo->update($answerId, $update);

        $this->logger->info('Essay scored', ['answer_id' => $answerId, 'marks' => $marksAwarded, 'reviewer_id' => $reviewerId]);

        return $this->answerRepo->find($answerId);
    }

    /**
     * Publishes all completed essay reviews for a quiz, making them visible
     * to students. Updates attempt.essay_score, recalculates total, and sets
     * essay_published_at timestamp. Called after a teacher confirms "Publish
     * Reviews" on the Results Dashboard.
     */
    public function publishReviews(int $quizId, int $reviewerId): array
    {
        // Find all attempts for this quiz with review_status = 'completed'
        // but essay_published_at IS NULL (not yet published).
        $attempts = $this->db->query(
            "SELECT DISTINCT qa.id, qa.student_id
             FROM quiz_attempts qa
             WHERE qa.quiz_id = ? AND qa.review_status = 'completed' AND qa.essay_published_at IS NULL
               AND qa.deleted_at IS NULL",
            [$quizId]
        );

        if (empty($attempts)) {
            return ['published_count' => 0, 'message' => 'No reviews to publish.'];
        }

        $this->db->beginTransaction();

        try {
            $publishedCount = 0;
            $uniqueStudents = [];

            foreach ($attempts as $attempt) {
                // Mark all essays for this attempt as published.
                $this->db->update('quiz_attempts', [
                    'essay_published_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$attempt['id']]);

                // Recalculate the attempt's total score now that essays are scored.
                $this->attemptRepo->recalculateAttemptScore($attempt['id']);

                $publishedCount++;
                $uniqueStudents[$attempt['student_id']] = true;
            }

            // Update each affected student's rolling stats.
            foreach (array_keys($uniqueStudents) as $studentId) {
                $this->attemptRepo->updateStudentStats($studentId);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Failed publishing reviews', ['quiz_id' => $quizId, 'error' => $e->getMessage()]);
            throw $e;
        }

        $this->logger->info('Reviews published', ['quiz_id' => $quizId, 'count' => $publishedCount, 'reviewer_id' => $reviewerId]);

        return [
            'published_count' => $publishedCount,
            'unique_students' => count($uniqueStudents),
            'message' => "Published reviews for $publishedCount attempt(s) across " . count($uniqueStudents) . ' student(s).',
        ];
    }

    /**
     * Marks an essay as "reviewed" without scoring it — used if a teacher
     * wants to note "this is empty, no score given" or skip an essay.
     * After all essays are either scored or marked reviewed, the attempt's
     * review_status becomes 'completed'.
     */
    public function markEssayReviewed(int $answerId, int $reviewerId, ?string $teacherNotes = null): array
    {
        $answer = $this->answerRepo->find($answerId);
        if (!$answer) {
            throw new NotFoundException('Answer');
        }

        if ($answer['type'] !== 'essay') {
            throw new ValidationException(['type' => 'Only essays can be marked reviewed.']);
        }

        $update = [
            'reviewed_at' => date('Y-m-d H:i:s'),
            'reviewed_by' => $reviewerId,
            'marks_awarded' => 0, // no points if not explicitly scored
        ];

        if ($teacherNotes !== null) {
            $update['teacher_notes'] = $teacherNotes;
        }

        $this->answerRepo->update($answerId, $update);

        // Check if all essays for this attempt are now reviewed.
        $this->markAttemptReviewCompleteIfReady($answer['attempt_id']);

        $this->logger->info('Essay marked reviewed (no score)', ['answer_id' => $answerId, 'reviewer_id' => $reviewerId]);

        return $this->answerRepo->find($answerId);
    }

    /**
     * Returns all essay answers for an attempt grouped by review status
     * — used by the Submission Detail screen's "Review & Score" tab.
     */
    public function getAttemptEssaysForReview(int $attemptId): array
    {
        $answers = $this->answerRepo->allForAttempt($attemptId);

        return array_values(array_filter($answers, fn($a) => $a['type'] === 'essay'));
    }

    /**
     * Checks if all essays for an attempt have been reviewed. If so,
     * updates the attempt's review_status to 'completed'.
     */
    private function markAttemptReviewCompleteIfReady(int $attemptId): void
    {
        // Count essays that still need review (reviewed_at IS NULL).
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS pending FROM quiz_attempt_answers qaa
             INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
             WHERE qaa.attempt_id = ? AND qq.type = 'essay'
               AND qaa.reviewed_at IS NULL",
            [$attemptId]
        );

        if ((int) ($row['pending'] ?? 0) === 0) {
            $this->db->update('quiz_attempts', ['review_status' => 'completed'], 'id = ?', [$attemptId]);
        }
    }
}
