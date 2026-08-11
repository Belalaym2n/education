<?php

namespace App\Services;

use App\Contracts\AttemptRepositoryInterface;
use App\Contracts\AttemptAnswerRepositoryInterface;
use App\Contracts\QuizRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\DTO\QuizAvailabilityDTO;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ConflictException;

class AttemptService
{
    private AttemptRepositoryInterface $attemptRepo;
    private AttemptAnswerRepositoryInterface $answerRepo;
    private QuizRepositoryInterface $quizRepo;
    private Database $db;
    private Logger $logger;

     public function __construct(
        AttemptRepositoryInterface $attemptRepo,
        AttemptAnswerRepositoryInterface $answerRepo,
        QuizRepositoryInterface $quizRepo,
        Database $db,
        Logger $logger
    ) {
        $this->attemptRepo = $attemptRepo;
        $this->answerRepo = $answerRepo;
        $this->quizRepo = $quizRepo;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Starts a new attempt for a student on a quiz, or resumes an existing
     * in-progress one. Uses a row lock on the "in progress" check + a unique
     * index race guard to make concurrent start requests safe (see C2).
     */
    public function startAttempt(int $quizId, int $studentId): array
    {
        $quiz = $this->quizRepo->find($quizId);
        if (!$quiz) {
            throw new NotFoundException('Quiz');
        }

        $availability = QuizAvailabilityDTO::fromQuizRow($quiz);
        if (!$availability->isVisibleToStudents || $availability->state !== 'live') {
            throw new ForbiddenException('This quiz is not available right now.');
        }

        $durationSeconds = (int) ($quiz['duration_seconds'] ?? 0);

        // Lock the student's attempt rows for this quiz to serialize
        // concurrent "start" calls (double-tap, retry-on-timeout, etc).
        $this->db->beginTransaction();

        try {
            $attempts = $this->db->query(
                "SELECT * FROM quiz_attempts
                 WHERE quiz_id = ? AND student_id = ? AND deleted_at IS NULL
                 ORDER BY attempt_number ASC
                 FOR UPDATE",
                [$quizId, $studentId]
            );

            $completedCount = count(array_filter($attempts, fn($a) => $a['status'] !== 'in_progress'));
            $inProgress = array_values(array_filter($attempts, fn($a) => $a['status'] === 'in_progress'));

            if (!empty($inProgress)) {
                $existing = $inProgress[0];
                $this->db->commit();
                $this->logger->info('Resuming existing attempt', ['attempt_id' => $existing['id'], 'student_id' => $studentId]);
                return $this->withRemainingTime($this->withQuestions($existing), $quiz);
            }

            if ($completedCount >= (int) $quiz['max_attempts']) {
                $this->db->rollBack();
                throw new ForbiddenException('You have reached the maximum number of attempts for this quiz.');
            }

            $attemptId = $this->attemptRepo->create([
                'quiz_id' => $quizId,
                'student_id' => $studentId,
                'quiz_version' => $quiz['version'],
                'attempt_number' => $completedCount + 1,
                'status' => 'in_progress',
                'started_at' => gmdate('Y-m-d H:i:s'),
                'ip_address' => $this->getClientIp(),
            ]);

            $questions = $this->db->query(
                "SELECT id FROM quiz_questions WHERE quiz_id = ? AND deleted_at IS NULL ORDER BY order_number",
                [$quizId]
            );

            foreach ($questions as $question) {
                $this->answerRepo->create([
                    'attempt_id' => $attemptId,
                    'question_id' => $question['id'],
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Failed starting attempt', ['quiz_id' => $quizId, 'student_id' => $studentId, 'error' => $e->getMessage()]);
            throw $e;
        }

        $attempt = $this->attemptRepo->find($attemptId);
        $this->logger->info('Attempt started', ['attempt_id' => $attemptId, 'student_id' => $studentId]);

        return $this->withRemainingTime($this->withQuestions($attempt), $quiz);
    }

    /**
     * Asserts the attempt exists and belongs to the given student.
     * Every mutating or attempt-scoped read method must call this first.
     */
    private function assertOwnership(array $attempt, int $studentId): void
    {
        if ((int) $attempt['student_id'] !== $studentId) {
            $this->logger->error('Attempt ownership violation attempt', [
                'attempt_id' => $attempt['id'],
                'owner_id' => $attempt['student_id'],
                'requested_by' => $studentId,
            ]);
            throw new ForbiddenException('You do not have access to this attempt.');
        }
    }

    public function saveAnswer(int $attemptId, int $questionId, array $answerData, int $studentId): array
    {
        $attempt = $this->attemptRepo->find($attemptId);
        if (!$attempt) {
            throw new NotFoundException('Attempt');
        }

        $this->assertOwnership($attempt, $studentId);

        if ($attempt['status'] !== 'in_progress') {
            throw new ForbiddenException('This attempt has already been submitted.');
        }

        // Server-authoritative expiry check — see C4.
        $quiz = $this->quizRepo->find($attempt['quiz_id']);
        if ($this->isExpired($attempt, $quiz)) {
            throw new ForbiddenException('Time is up for this attempt. It will be auto-submitted.');
        }

        $answer = $this->db->selectOne(
            "SELECT a.id, q.id AS question_id, q.type
             FROM quiz_attempt_answers a
             INNER JOIN quiz_questions q ON q.id = a.question_id
             WHERE a.attempt_id = ? AND a.question_id = ?",
            [$attemptId, $questionId]
        );

        if (!$answer) {
            throw new NotFoundException('Question not found in this attempt.');
        }

        $type = $answer['type'];

        $hasChoices = array_key_exists('selected_choice_ids', $answerData);
        $hasText = array_key_exists('answer_text', $answerData);
        $hasImage = array_key_exists('answer_image_url', $answerData);

        $count = ($hasChoices ? 1 : 0) + ($hasText ? 1 : 0) + ($hasImage ? 1 : 0);

        if ($count == 0) {
            throw new ValidationException(['answer' => 'Answer is required.']);
        }

        if ($count > 1) {
            throw new ValidationException(['answer' => 'Only one answer type may be submitted.']);
        }

        if (in_array($type, ['mcq', 'true_false'], true)) {
            if (!$hasChoices || !is_array($answerData['selected_choice_ids'])) {
                throw new ValidationException(['selected_choice_ids' => 'This question requires one choice.']);
            }
            if (count($answerData['selected_choice_ids']) !== 1) {
                throw new ValidationException(['selected_choice_ids' => 'Exactly one choice must be selected.']);
            }
        }

        if ($type === 'multi_select') {
            if (!$hasChoices || !is_array($answerData['selected_choice_ids'])) {
                throw new ValidationException(['selected_choice_ids' => 'This question requires choices.']);
            }
            if (empty($answerData['selected_choice_ids'])) {
                throw new ValidationException(['selected_choice_ids' => 'Select at least one choice.']);
            }
        }

        if ($type === 'essay') {
            if (!$hasText && !$hasImage) {
                throw new ValidationException(['answer' => 'Essay answer must contain text or an image.']);
            }
            if ($hasText) {
                if (!is_string($answerData['answer_text'])) {
                    throw new ValidationException(['answer_text' => 'Answer must be text.']);
                }
                if (trim($answerData['answer_text']) === '') {
                    throw new ValidationException(['answer_text' => 'Answer cannot be empty.']);
                }
                if (mb_strlen($answerData['answer_text']) > 5000) {
                    throw new ValidationException(['answer_text' => 'Answer is too long (max 5000 characters).']);
                }
            }
            if ($hasImage) {
                if (!is_string($answerData['answer_image_url'])) {
                    throw new ValidationException(['answer_image_url' => 'Image URL must be a string.']);
                }
                if (trim($answerData['answer_image_url']) === '') {
                    throw new ValidationException(['answer_image_url' => 'Image URL cannot be empty.']);
                }
            }
        }

        if ($hasChoices) {
            $validChoices = $this->db->query(
                "SELECT id FROM quiz_question_choices WHERE question_id = ?",
                [$questionId]
            );
            $allowedIds = array_map(fn($c) => (int) $c['id'], $validChoices);

            foreach ($answerData['selected_choice_ids'] as $choiceId) {
                if (!is_numeric($choiceId) || !in_array((int) $choiceId, $allowedIds, true)) {
                    throw new ValidationException(['selected_choice_ids' => 'One or more selected choices are invalid.']);
                }
            }

            // multi_select: dedupe + reject duplicate ids in the payload
            if ($type === 'multi_select') {
                $unique = array_unique(array_map('intval', $answerData['selected_choice_ids']));
                if (count($unique) !== count($answerData['selected_choice_ids'])) {
                    throw new ValidationException(['selected_choice_ids' => 'Duplicate choices are not allowed.']);
                }
            }
        }

        $update = [];
        if ($hasChoices) {
            $update['selected_choice_ids'] = json_encode(array_values(array_map('intval', $answerData['selected_choice_ids'])));
        }
        if ($hasText) {
            $update['answer_text'] = trim($answerData['answer_text']);
        }
        if ($hasImage) {
            $update['answer_image_url'] = trim($answerData['answer_image_url']);
        }
        $update['updated_at'] = gmdate('Y-m-d H:i:s');

        $this->answerRepo->update($answer['id'], $update);

        return $this->answerRepo->find($answer['id']);
    }

    /**
     * Submits the attempt with an idempotency guard: the UPDATE itself only
     * succeeds if status is still 'in_progress', so two concurrent submit
     * calls can't both grade/finalize (see C2).
     */
    public function submitAttempt(int $attemptId, int $studentId): array
    {
        $attempt = $this->attemptRepo->find($attemptId);
        if (!$attempt) {
            throw new NotFoundException('Attempt');
        }

        $this->assertOwnership($attempt, $studentId);

        $quiz = $this->quizRepo->find($attempt['quiz_id']);
        if (!$quiz) {
            throw new NotFoundException('Quiz');
        }

        return $this->finalizeAttempt($attempt, $quiz, 'submitted');
    }

    /**
     * Auto-submits when the timer expires. No ownership check by design —
     * this may be triggered by a scheduled job with no authenticated
     * student in context. If called from an authenticated request, the
     * controller still passes studentId for logging/ownership on the
     * request-triggered path.
     */
    public function autoSubmitAttempt(int $attemptId, ?int $studentId = null): array
    {
        $attempt = $this->attemptRepo->find($attemptId);
        if (!$attempt) {
            throw new NotFoundException('Attempt');
        }

        if ($studentId !== null) {
            $this->assertOwnership($attempt, $studentId);
        }

        if ($attempt['status'] !== 'in_progress') {
            // Already finalized — return current state instead of erroring,
            // since auto-submit racing with manual submit is expected.
            return $attempt;
        }

        $quiz = $this->quizRepo->find($attempt['quiz_id']);

        return $this->finalizeAttempt($attempt, $quiz, 'auto_submitted');
    }

    private function finalizeAttempt(array $attempt, array $quiz, string $finalStatus): array
    {
        $attemptId = (int) $attempt['id'];

        $this->db->beginTransaction();

        try {
            // Idempotent status transition: only rows still 'in_progress' are
            // touched. If 0 rows affected, someone else already finalized it
            // (double submit / auto-submit race) — treat as a no-op success
            // rather than double-grading.
            $durationUsed = max(0, (int) (time() - strtotime($attempt['started_at'] . ' UTC')));
            $durationUsed = min($durationUsed, (int) $quiz['duration_seconds'] + 30); // clamp clock drift

            $affected = $this->db->update('quiz_attempts', [
                'status' => $finalStatus,
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'duration_used_seconds' => $durationUsed,
            ], 'id = ? AND status = ?', [$attemptId, 'in_progress']);

            if ($affected === 0) {
                // Already finalized by a concurrent request.
                $this->db->rollBack();
                $this->logger->info('Duplicate submit ignored (already finalized)', ['attempt_id' => $attemptId]);
                return $this->attemptRepo->find($attemptId);
            }

            $this->attemptRepo->autoGradeObjectiveAnswers($attemptId);

            $essayAnswers = $this->db->query(
                "SELECT COUNT(*) AS count FROM quiz_attempt_answers qaa
                 INNER JOIN quiz_questions qq ON qq.id = qaa.question_id
                 WHERE qaa.attempt_id = ? AND qq.type = 'essay' AND qq.deleted_at IS NULL",
                [$attemptId]
            );
            $hasEssays = (int) ($essayAnswers[0]['count'] ?? 0) > 0;

            $resultVisibleAt = !empty($quiz['show_result_immediately'])
                ? gmdate('Y-m-d H:i:s')
                : trim(($quiz['end_date'] ?? gmdate('Y-m-d')) . ' ' . ($quiz['end_time'] ?? '23:59:59'));

            $this->attemptRepo->update($attemptId, [
                'review_status' => $hasEssays ? 'pending' : 'not_required',
                'result_visible_at' => $resultVisibleAt,
            ]);

            $this->attemptRepo->recalculateAttemptScore($attemptId);
            $this->attemptRepo->updateStudentStats((int) $attempt['student_id']);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Failed finalizing attempt', [
                'attempt_id' => $attemptId,
                'status' => $finalStatus,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('Attempt finalized', ['attempt_id' => $attemptId, 'status' => $finalStatus]);

        return $this->attemptRepo->find($attemptId);
    }

    public function getAttempt(int $attemptId, int $studentId): array
    {
        $attempt = $this->attemptRepo->find($attemptId);
        if (!$attempt) {
            throw new NotFoundException('Attempt');
        }

        $this->assertOwnership($attempt, $studentId);

        $quiz = $this->quizRepo->find($attempt['quiz_id']);

        // If the attempt is in_progress but time has actually run out
        // (app was killed, network was down, etc.), auto-submit it now
        // rather than letting the student keep answering past the limit.
        if ($attempt['status'] === 'in_progress' && $quiz && $this->isExpired($attempt, $quiz)) {
            return $this->finalizeAttempt($attempt, $quiz, 'auto_submitted');
        }

        return $this->withRemainingTime($this->withQuestions($attempt), $quiz);
    }

    public function getResultsForStudent(int $attemptId, int $studentId): array
    {
        $attempt = $this->attemptRepo->find($attemptId);
        if (!$attempt) {
            throw new NotFoundException('Attempt');
        }

        $this->assertOwnership($attempt, $studentId);

        if ($attempt['status'] === 'in_progress') {
            throw new ForbiddenException('This attempt has not been submitted yet.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $resultVisibleAt = $attempt['result_visible_at']
            ? new \DateTimeImmutable($attempt['result_visible_at'], new \DateTimeZone('UTC'))
            : null;

        if ($resultVisibleAt && $now < $resultVisibleAt) {
            throw new ForbiddenException('Results for this quiz are not yet visible.');
        }

        $result = array_merge($attempt, [
            'answers' => $this->answerRepo->allForAttempt($attemptId),
        ]);

        if (empty($attempt['essay_published_at'])) {
            $result['essay_score'] = null;
            $result['answers'] = array_map(function ($answer) {
                if ($answer['type'] === 'essay') {
                    $answer['is_visible'] = false;
                    unset($answer['answer_text'], $answer['answer_image_url']);
                }
                return $answer;
            }, $result['answers']);
        }

        return $result;
    }

    /**
     * Computes remaining_seconds and expires_at server-side and attaches
     * them to the attempt payload. This is the single source of truth the
     * Flutter timer must use — see C4/C5.
     */
    private function withRemainingTime(array $attempt, ?array $quiz): array
    {
        $duration = (int) ($quiz['duration_seconds'] ?? 0);
        $startedAt = strtotime($attempt['started_at'] . ' UTC');
        $now = time();
        $elapsed = max(0, $now - $startedAt);
        $remaining = max(0, $duration - $elapsed);
$attempt['duration_seconds'] = $duration;
$attempt['duration_minutes'] = (int) ceil($duration / 60);
          $attempt['remaining_seconds'] = $remaining;
        $attempt['server_time'] = gmdate('Y-m-d\TH:i:s\Z', $now);
        $attempt['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $startedAt + $duration);

        return $attempt;
    }

    private function isExpired(array $attempt, ?array $quiz): bool
    {
        if (!$quiz) {
            return false;
        }
        $duration = (int) ($quiz['duration_seconds'] ?? 0);
        $startedAt = strtotime($attempt['started_at'] . ' UTC');
        return (time() - $startedAt) >= $duration;
    }

    private function withQuestions(array $attempt): array
    {
        return array_merge($attempt, [
            'questions' => $this->db->query(
                "SELECT id, type, question_text, image_url, marks, order_number, explanation
                 FROM quiz_questions
                 WHERE quiz_id = ? AND deleted_at IS NULL
                 ORDER BY order_number ASC",
                [$attempt['quiz_id']]
            ),
            'answers' => $this->answerRepo->allForAttempt($attempt['id']),
        ]);
    }

    private function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function getLatestResultForQuiz(int $quizId, int $studentId): array
{
    // Make sure the quiz exists.
    $quiz = $this->quizRepo->find($quizId);

    if (!$quiz) {
        throw new NotFoundException('Quiz');
    }

    // Find the latest completed attempt for this student.
    $attempt = $this->attemptRepo->findLatestByQuizAndStudent(
        $quizId,
        $studentId
    );

    if (!$attempt) {
        throw new NotFoundException('Quiz result');
    }

    // Reuse the existing result logic.
    // This handles:
    // - ownership
    // - result visibility
    // - essay visibility
    // - answers
    return $this->getResultsForStudent(
        (int) $attempt['id'],
        $studentId
    );
}
} 