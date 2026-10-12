<?php

namespace App\Services;

use App\Config\GameExamConstants as C;
use App\Contracts\GameExamRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Support\Clock;
use App\Validators\GameExamValidator;

/**
 * Teacher/dashboard operations on game exams. Every multi-row write runs in
 * one transaction so an exam is never left with half its questions.
 */
class GameExamService
{
    private GameExamRepositoryInterface $exams;
    private GameExamValidator $validator;
    private GameExamPresenter $presenter;
    private Database $db;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        GameExamRepositoryInterface $exams,
        GameExamValidator $validator,
        GameExamPresenter $presenter,
        Database $db,
        Clock $clock,
        Logger $logger
    ) {
        $this->exams = $exams;
        $this->validator = $validator;
        $this->presenter = $presenter;
        $this->db = $db;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /** @return array{data: array, meta: array} */
    public function list(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        $page = $this->exams->list($filters, $limit, $offset, $sort, $order);
        return [
            'data' => array_map(fn($r) => $this->presenter->examSummary($r), $page['rows']),
            'meta' => ['total' => $page['total'], 'limit' => $limit, 'offset' => $offset, 'sort' => $sort, 'order' => $order],
        ];
    }

    public function statistics(array $filters): array
    {
        $recentSince = $this->clock->now()->modify('-' . C::RECENT_DAYS . ' days')->format(Clock::DB_FORMAT);
        $row = $this->exams->statistics($filters, $recentSince);

        $total = (int) ($row['total'] ?? 0);
        $published = (int) ($row['published'] ?? 0);
        $questions = (int) ($row['total_questions'] ?? 0);

        return [
            'total' => $total,
            'published' => $published,
            'drafts' => (int) ($row['drafts'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
            'deleted' => (int) ($row['deleted'] ?? 0),
            'recent' => (int) ($row['recent'] ?? 0),
            'recent_days' => C::RECENT_DAYS,
            'total_questions' => $questions,
            'total_attempts' => (int) ($row['total_attempts'] ?? 0),
            'avg_questions_per_exam' => $total > 0 ? round($questions / $total, 1) : 0,
            'published_percentage' => $total > 0 ? round($published / $total * 100, 1) : 0,
        ];
    }

    public function show(int $id): array
    {
        $exam = $this->exams->find($id, true);
        if (!$exam) {
            throw new NotFoundException('Game exam');
        }
        return $this->presenter->examDetail($exam, $this->exams->questions($id));
    }

    public function create(array $payload, int $userId): array
    {
        $clean = $this->validator->validateExam($payload);
        $this->assertStage($clean['exam']['stage_id']);
        $now = $this->clock->nowDb();

        $this->db->beginTransaction();
        try {
            $examId = $this->exams->create(array_merge($clean['exam'], [
                'status' => C::STATUS_DRAFT,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $this->writeQuestions($examId, $clean['questions'], $now, false);
            $this->exams->refreshCounters($examId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Game exam create failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->logger->info('Game exam created', ['id' => $examId, 'by' => $userId]);
        return $this->show($examId);
    }

    public function update(int $id, array $payload, int $userId): array
    {
        $now = $this->clock->nowDb();

        $this->db->beginTransaction();
        try {
            $exam = $this->exams->findForUpdate($id);
            if (!$exam || $exam['deleted_at'] !== null) {
                throw new NotFoundException('Game exam');
            }

            $locked = (int) $exam['attempts_count'] > 0;
            $metadataOnly = !empty($payload['metadata_only']);
            if ($locked && !$metadataOnly) {
                // DEC-10: never rewrite questions/scoring under existing attempts.
                throw new AppException(
                    'This exam already has attempts. Only the headline and description can change; duplicate it to edit questions.',
                    409,
                    C::ERR_EXAM_HAS_ATTEMPTS
                );
            }

            $clean = $this->validator->validateExam($payload, $metadataOnly);
            if (!$metadataOnly) {
                $this->assertStage($clean['exam']['stage_id']);
            }

            $this->exams->update($id, array_merge($clean['exam'], ['updated_by' => $userId, 'updated_at' => $now]));

            if (!$metadataOnly) {
                $this->writeQuestions($id, $clean['questions'], $now, true);
                $this->exams->refreshCounters($id);

                // A published exam may not end up empty.
                if ($exam['status'] === C::STATUS_PUBLISHED && count($clean['questions']) === 0) {
                    throw new ValidationException(['questions' => 'a published exam needs at least one question']);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info('Game exam updated', ['id' => $id, 'by' => $userId, 'metadata_only' => $metadataOnly]);
        return $this->show($id);
    }

    public function delete(int $id, int $userId): void
    {
        if (!$this->exams->find($id)) {
            throw new NotFoundException('Game exam');
        }
        $this->exams->softDelete($id, $this->clock->nowDb(), $userId);
        $this->logger->info('Game exam soft-deleted', ['id' => $id, 'by' => $userId]);
    }

    public function restore(int $id, int $userId): array
    {
        $exam = $this->exams->find($id, true);
        if (!$exam) {
            throw new NotFoundException('Game exam');
        }
        $this->exams->restore($id, $userId);
        $this->logger->info('Game exam restored', ['id' => $id, 'by' => $userId]);
        return $this->show($id);
    }

    public function publish(int $id, int $userId): array
    {
        $exam = $this->exams->find($id);
        if (!$exam) {
            throw new NotFoundException('Game exam');
        }
        $questions = $this->exams->questions($id);
        if (count($questions) < 1) {
            throw new AppException('An exam needs at least one question before publishing.', 409, C::ERR_EXAM_NOT_PUBLISHABLE);
        }
        foreach ($questions as $q) {
            $correct = array_filter($q['choices'], fn($c) => (int) $c['is_correct'] === 1);
            if (count($q['choices']) < C::MIN_CHOICES || count($correct) !== 1) {
                throw new AppException(
                    'Every question needs at least two choices and exactly one correct answer.',
                    409,
                    C::ERR_EXAM_NOT_PUBLISHABLE,
                    ['question_id' => (int) $q['id']]
                );
            }
        }

        $now = $this->clock->nowDb();
        $this->exams->update($id, [
            'status' => C::STATUS_PUBLISHED,
            'published_at' => $exam['published_at'] ?? $now,
            'updated_by' => $userId,
            'updated_at' => $now,
        ]);
        $this->logger->info('Game exam published', ['id' => $id, 'by' => $userId]);
        return $this->show($id);
    }

    public function unpublish(int $id, int $userId): array
    {
        if (!$this->exams->find($id)) {
            throw new NotFoundException('Game exam');
        }
        // DEC-11: running attempts keep going until expires_at; new starts stop.
        $this->exams->update($id, ['status' => C::STATUS_DRAFT, 'updated_by' => $userId, 'updated_at' => $this->clock->nowDb()]);
        $this->logger->info('Game exam unpublished', ['id' => $id, 'by' => $userId]);
        return $this->show($id);
    }

    public function duplicate(int $id, int $userId): array
    {
        $exam = $this->exams->find($id, true);
        if (!$exam) {
            throw new NotFoundException('Game exam');
        }
        $questions = $this->exams->questions($id);
        $now = $this->clock->nowDb();

        $headline = mb_substr($exam['headline'], 0, 200 - mb_strlen(C::DUPLICATE_SUFFIX)) . C::DUPLICATE_SUFFIX;

        $this->db->beginTransaction();
        try {
            $newId = $this->exams->create([
                'stage_id' => $exam['stage_id'],
                'headline' => $headline,
                'description' => $exam['description'],
                'duration_seconds' => $exam['duration_seconds'],
                'status' => C::STATUS_DRAFT,
                'reveal_policy' => $exam['reveal_policy'],
                'shuffle_choices' => $exam['shuffle_choices'],
                'pass_percentage' => $exam['pass_percentage'],
                'available_from' => $exam['available_from'],
                'available_until' => $exam['available_until'],
                'offline_grace_seconds' => $exam['offline_grace_seconds'],
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($questions as $i => $q) {
                $qid = $this->exams->insertQuestion($newId, $q, $i);
                $this->exams->replaceChoices($qid, array_map(
                    fn($c) => ['choice_text' => $c['choice_text'], 'is_correct' => (int) $c['is_correct']],
                    $q['choices']
                ));
            }
            $this->exams->refreshCounters($newId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info('Game exam duplicated', ['from' => $id, 'to' => $newId, 'by' => $userId]);
        return $this->show($newId);
    }

    public function reorder(int $id, array $payload, int $userId): array
    {
        $ids = $this->validator->validateOrder($payload);
        if (!$this->exams->find($id)) {
            throw new NotFoundException('Game exam');
        }
        $current = $this->exams->questionIds($id);
        $a = $ids;
        $b = $current;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new ValidationException(['order' => 'order must contain every question of this exam exactly once']);
        }

        // Positions inside existing attempts are snapshotted, so reordering
        // is safe even after attempts exist.
        $this->db->beginTransaction();
        try {
            $this->exams->updateQuestionOrder($id, $ids);
            $this->exams->update($id, ['updated_by' => $userId, 'updated_at' => $this->clock->nowDb()]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->show($id);
    }

    /**
     * Upserts questions in payload order. Existing ids are kept stable;
     * questions missing from the payload are soft-deleted.
     */
    private function writeQuestions(int $examId, array $questions, string $now, bool $isUpdate): void
    {
        $existing = $isUpdate ? $this->exams->questionIds($examId) : [];
        $keep = [];

        foreach ($questions as $order => $q) {
            if ($q['id'] !== null && in_array($q['id'], $existing, true)) {
                $this->exams->updateQuestion($examId, $q['id'], $q, $order);
                $questionId = $q['id'];
            } else {
                $questionId = $this->exams->insertQuestion($examId, $q, $order);
            }
            $keep[] = $questionId;
            $this->exams->replaceChoices($questionId, $q['choices']);
        }

        if ($isUpdate) {
            $this->exams->softDeleteQuestionsExcept($examId, $keep, $now);
        }
    }

    private function assertStage(?int $stageId): void
    {
        if ($stageId === null || !$this->exams->stageExists($stageId)) {
            throw new ValidationException(['stage_id' => 'stage does not exist']);
        }
    }
}
