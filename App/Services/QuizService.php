<?php

namespace App\Services;

use App\Contracts\QuizRepositoryInterface;
use App\Contracts\StudentRepositoryInterface;

use App\Contracts\QuizQuestionRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\DTO\QuizAvailabilityDTO;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ForbiddenException;
use App\Models\QuizStatus;

class QuizService
{
        private StudentRepositoryInterface $studentRepo;

    private QuizRepositoryInterface $quizRepo;
    private QuizQuestionRepositoryInterface $questionRepo;
    private Database $db;  
    private Logger $logger;

    public function __construct(
        QuizRepositoryInterface $quizRepo,
                StudentRepositoryInterface $studentRepo,

        QuizQuestionRepositoryInterface $questionRepo,
        Database $db,
        Logger $logger
    ) {
        $this->quizRepo = $quizRepo;
                $this->studentRepo = $studentRepo;

        $this->questionRepo = $questionRepo;
        $this->db = $db;
        $this->logger = $logger;
    }

    public function getQuiz(int $id): array
    {
        $quiz = $this->quizRepo->find($id);

        if (!$quiz) {
            throw new NotFoundException('Quiz');
        }

        return $this->withDerivedFields($quiz);
    } 
    public function getStageQuizzes(int $studentId,array $filters, int $limit, int $offset, string $sort, string $order): array
    {


      $stageId = $this->resolveStudentStageId($studentId);

              $filters['stage_id'] = $stageId;

        $rows = $this->quizRepo->getStageQuizzes($stageId, $filters, $limit, $offset, $sort, $order);
        $total = $this->quizRepo->count($filters);

        return [
            'data' => array_map(fn($row) => $this->withDerivedFields($row), $rows),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'order' => $order,
            ],
        ]; 
    }


  private function resolveStudentStageId(int $authUserId): int
    {
        $student = $this->studentRepo->find($authUserId);

        if (!$student || empty($student['stage_id'])) {
            throw new NotFoundException('Student');
        }

        return (int) $student['stage_id'];
    }

    /**
     * Returns the quiz plus its full question tree — the payload the
     * question builder loads when a teacher reopens a draft/edits an
     * existing quiz.
     */
    public function getQuizWithQuestions(int $id): array
    {
        $quiz = $this->getQuiz($id);
        $quiz['questions'] = $this->questionRepo->allForQuiz($id);

        return $quiz;
    }

    public function listQuizzes(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        $rows = $this->quizRepo->paginate($filters, $limit, $offset, $sort, $order);
        $total = $this->quizRepo->count($filters);

        return [
            'data' => array_map(fn($row) => $this->withDerivedFields($row), $rows),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'order' => $order,
            ],
        ];
    }

    public function getInsights(array $filters): array
    {
        return $this->quizRepo->aggregateInsights($filters);
    }

    /**
     * Creates a quiz in `draft` status. Questions are NOT accepted here —
     * per your spec, the question builder is local-only on the client and
     * everything ships together on submitQuestions()/publish(). This keeps
     * "create quiz" a fast, single-purpose call.
     */
    public function createQuiz(array $data, int $userId): array
    {
        $data['slug'] = $this->generateUniqueSlug($data['headline']);
        $data['status'] = QuizStatus::DRAFT;
        $data['created_by'] = $userId;
        $data['version'] = 1;
        $data['total_marks'] = 0;
        $data['question_count'] = 0;

        $id = $this->quizRepo->create($data);
        $this->logger->info('Quiz created', ['id' => $id, 'created_by' => $userId]);

        return $this->getQuiz($id);
    }

    public function updateQuiz(int $id, array $data, int $userId): array
    {
        $quiz = $this->getQuiz($id);

        if (isset($data['headline']) && $data['headline'] !== $quiz['headline']) {
            $data['slug'] = $this->generateUniqueSlug($data['headline'], $id);
        }

        $data['updated_by'] = $userId;
        $this->quizRepo->update($id, $data);

        $this->logger->info('Quiz updated', ['id' => $id, 'updated_by' => $userId]);

        return $this->getQuiz($id);
    }

    /**
     * Full builder save: replaces every question for the quiz in one
     * transaction, then recalculates the denormalized totals. If the quiz
     * already has attempts, this is destructive to grading consistency
     * unless the caller explicitly confirms via $force — matching the
     * "version" safeguard described in the schema notes.
     */
    public function saveQuestions(int $quizId, array $questions, bool $force = false): array
    {
        $quiz = $this->getQuiz($quizId);
        $attemptsCount = $this->quizRepo->countAttempts($quizId);

        if ($attemptsCount > 0 && !$force) {
            throw new ValidationException([
                'questions' => "This quiz already has {$attemptsCount} attempt(s). "
                    . "Editing questions will not affect already-graded attempts, but may "
                    . "affect fairness for in-progress attempts. Resend with force=true to confirm.",
            ]);
        }

        $this->db->beginTransaction();

        try {
            $this->questionRepo->deleteAllForQuiz($quizId);

            foreach ($questions as $index => $question) {
                $questionId = $this->questionRepo->create([
                    'quiz_id' => $quizId,
                    'type' => $question['type'],
                    'question_text' => $question['question_text'],
                    'image_url' => $question['image_url'] ?? null,
                    'media_url' => $question['media_url'] ?? null,
                    'marks' => $question['marks'],
                    'negative_marks' => $question['negative_marks'] ?? 0,
                    'explanation' => $question['explanation'] ?? null,
                    'shuffle_choices' => !empty($question['shuffle_choices']) ? 1 : 0,
                    'order_number' => $question['order_number'] ?? $index,
                    'meta' => isset($question['meta']) ? json_encode($question['meta']) : null,
                ]);

                if (!empty($question['choices'])) {
                    $this->questionRepo->replaceChoices($questionId, $question['choices']);
                }
            }

            if ($attemptsCount > 0) {
                $this->quizRepo->update($quizId, ['version' => $quiz['version'] + 1]);
            }

            $this->quizRepo->recalculateTotals($quizId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Failed saving quiz questions', ['quiz_id' => $quizId, 'error' => $e->getMessage()]);
            throw $e;
        }

        $this->logger->info('Quiz questions saved', ['quiz_id' => $quizId, 'count' => count($questions)]);

        return $this->getQuizWithQuestions($quizId);
    }

    public function publish(int $id): array
    {
        $quiz = $this->getQuiz($id);

        if ((int) $quiz['question_count'] === 0) {
            throw new ValidationException(['status' => 'Cannot publish a quiz with no questions']);
        }

        $this->quizRepo->update($id, [
            'status' => QuizStatus::PUBLISHED,
            'published_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('Quiz published', ['id' => $id]);

        return $this->getQuiz($id);
    }

    public function unpublish(int $id): array
    {
        $this->getQuiz($id);
        $this->quizRepo->update($id, ['status' => QuizStatus::DRAFT]);
        $this->logger->info('Quiz unpublished', ['id' => $id]);

        return $this->getQuiz($id);
    }

    public function archive(int $id): array
    {
        $this->getQuiz($id);
        $this->quizRepo->update($id, [
            'status' => QuizStatus::ARCHIVED,
            'archived_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logger->info('Quiz archived', ['id' => $id]);

        return $this->getQuiz($id);
    }

    public function toggleVisibility(int $id, bool $visible): array
    {
        $this->getQuiz($id);
        $this->quizRepo->update($id, ['is_visible' => $visible ? 1 : 0]);

        return $this->getQuiz($id);
    }

    /**
     * Duplicates a quiz AND its questions/choices as a new draft. Attempts
     * are never copied — a duplicate starts with a clean slate.
     */
    public function duplicate(int $id, int $userId): array
    {
        $original = $this->getQuizWithQuestions($id);

        $this->db->beginTransaction();

        try {
            $newHeadline = $original['headline'] . ' (Copy)';
            $newId = $this->quizRepo->create([
                'stage_id' => $original['stage_id'],
                'academic_year_id' => $original['academic_year_id'],
                'headline' => $newHeadline,
                'slug' => $this->generateUniqueSlug($newHeadline),
                'description' => $original['description'],
                'instructions' => $original['instructions'],
                'duration_seconds' => $original['duration_seconds'],
                'start_date' => $original['start_date'],
                'end_date' => $original['end_date'],
                'start_time' => $original['start_time'],
                'end_time' => $original['end_time'],
                'status' => QuizStatus::DRAFT,
                'show_result_immediately' => $original['show_result_immediately'],
                'passing_score' => $original['passing_score'],
                'shuffle_questions' => $original['shuffle_questions'],
                'shuffle_answers' => $original['shuffle_answers'],
                'allow_retry' => $original['allow_retry'],
                'max_attempts' => $original['max_attempts'],
                'negative_marking' => $original['negative_marking'],
                'created_by' => $userId,
                'version' => 1,
                'total_marks' => 0,
                'question_count' => 0,
            ]);

            foreach ($original['questions'] as $index => $question) {
                $newQuestionId = $this->questionRepo->create([
                    'quiz_id' => $newId,
                    'type' => $question['type'],
                    'question_text' => $question['question_text'],
                    'image_url' => $question['image_url'],
                    'media_url' => $question['media_url'],
                    'marks' => $question['marks'],
                    'negative_marks' => $question['negative_marks'],
                    'explanation' => $question['explanation'],
                    'shuffle_choices' => $question['shuffle_choices'],
                    'order_number' => $question['order_number'] ?? $index,
                    'meta' => $question['meta'],
                ]);

                if (!empty($question['choices'])) {
                    $this->questionRepo->replaceChoices($newQuestionId, $question['choices']);
                }
            }

            $this->quizRepo->recalculateTotals($newId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Failed duplicating quiz', ['quiz_id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }

        $this->logger->info('Quiz duplicated', ['from' => $id, 'to' => $newId]);

        return $this->getQuiz($newId);
    }

    public function deleteQuiz(int $id): void
    {
        $this->getQuiz($id);

        $attemptsCount = $this->quizRepo->countAttempts($id);
        if ($attemptsCount > 0) {
            throw new ValidationException([
                'quiz' => "Cannot delete: {$attemptsCount} student attempt(s) exist. Archive it instead.",
            ]);
        }

        $this->quizRepo->softDelete($id);
        $this->logger->info('Quiz deleted', ['id' => $id]);
    }

    private function withDerivedFields(array $quiz): array
    {
        $quiz['availability'] = QuizAvailabilityDTO::fromQuizRow($quiz)->toArray();
        $quiz['estimated_duration_minutes'] = (int) round(($quiz['duration_seconds'] ?? 0) / 60);

        return $quiz;
    }

    private function generateUniqueSlug(string $headline, ?int $excludeId = null): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $headline), '-'));
        if ($base === '') {
            $base = 'quiz';
        }

        $slug = $base;
        $suffix = 1;

        while ($this->quizRepo->slugExists($slug, $excludeId)) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
