<?php

namespace App\Services;

use App\Config\GameExamConstants as C;
use App\Contracts\GameExamAttemptRepositoryInterface;
use App\Contracts\GameExamRepositoryInterface;
use App\Contracts\PointsLedgerRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Support\Clock;
use DateTimeImmutable;
use PDOException;

/**
 * Student gameplay. The server is the only authority for correctness,
 * points, timers and completion; the client only sends "student X picked
 * choice Y for question Z on attempt N" plus a client_action_id.
 *
 * Concurrency model: every mutation locks the attempt row
 * (SELECT ... FOR UPDATE) so double taps, parallel syncs and a second
 * device are serialized. Idempotency: UNIQUE(attempt_id, client_action_id)
 * replays the stored response; UNIQUE(attempt_id, question_id, attempt_no)
 * makes a third attempt or a racing duplicate impossible; the ledger's
 * UNIQUE(source_type, source_id) makes double points impossible.
 */
class GameExamPlayService
{
    private const STUDENT_ROLE_ID = 1;

    private GameExamRepositoryInterface $exams;
    private GameExamAttemptRepositoryInterface $attempts;
    private PointsLedgerRepositoryInterface $ledger;
    private GameScoringPolicy $policy;
    private GameExamPresenter $presenter;
    private Database $db;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        GameExamRepositoryInterface $exams,
        GameExamAttemptRepositoryInterface $attempts,
        PointsLedgerRepositoryInterface $ledger,
        GameScoringPolicy $policy,
        GameExamPresenter $presenter,
        Database $db,
        Clock $clock,
        Logger $logger
    ) {
        $this->exams = $exams;
        $this->attempts = $attempts;
        $this->ledger = $ledger;
        $this->policy = $policy;
        $this->presenter = $presenter;
        $this->db = $db;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    // ================================================================ reads

    /**
     * Resolves the student profile from the JWT user id — never from the
     * request body. Also re-checks role = student: the production data has
     * admin accounts that own a `students` row (e.g. user 73), so the route
     * middleware alone is not trusted for this.
     */
    public function resolveStudent(int $userId): array
    {
        $row = $this->db->selectOne(
            "SELECT s.id, s.stage_id
             FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE s.user_id = ? AND u.role_id = ? AND s.deleted_at IS NULL
               AND u.deleted_at IS NULL AND u.is_active = 1",
            [$userId, self::STUDENT_ROLE_ID]
        );
        if (!$row) {
            throw new AppException('Student profile not found for this account.', 403, C::ERR_STUDENT_PROFILE_MISSING);
        }
        return ['id' => (int) $row['id'], 'stage_id' => (int) $row['stage_id']];
    }

    public function listExams(int $userId, array $filters, int $limit, int $offset): array
    {
        $student = $this->resolveStudent($userId);
        $page = $this->exams->listForStudent($student['id'], $student['stage_id'], $filters, $this->clock->nowDb(), $limit, $offset);

        return [
            'data' => array_map(fn($r) => $this->presenter->studentExamCard($r), $page['rows']),
            'meta' => ['total' => $page['total'], 'limit' => $limit, 'offset' => $offset, 'sort' => 'published_at', 'order' => 'desc'],
        ];
    }

    public function showExam(int $userId, int $examId): array
    {
        $student = $this->resolveStudent($userId);
        $exam = $this->exams->findVisibleForStudent($examId, $student['id'], $student['stage_id'], $this->clock->nowDb());
        if (!$exam) {
            throw new NotFoundException('Game exam');
        }
        $attempt = $exam['attempt_id'] ? $this->attempts->find((int) $exam['attempt_id']) : null;

        return [
            'id' => (int) $exam['id'],
            'stage_id' => (int) $exam['stage_id'],
            'stage_name' => $exam['stage_name'],
            'headline' => $exam['headline'],
            'description' => $exam['description'],
            'duration_seconds' => (int) $exam['duration_seconds'],
            'question_count' => (int) $exam['question_count'],
            'max_points' => (int) $exam['max_points'],
            'pass_percentage' => (float) $exam['pass_percentage'],
            'reveal_policy' => $exam['reveal_policy'],
            'can_start' => $attempt === null && $this->isStartable($exam, $student['stage_id']),
            'my_attempt' => $attempt ? $this->presenter->attemptTotals($attempt) : null,
        ];
    }

    public function state(int $userId, int $examId, int $attemptId): array
    {
        $student = $this->resolveStudent($userId);
        $attempt = $this->ownedAttempt($student['id'], $examId, $attemptId);
        $this->finalizeIfPastGrace($attempt);
        return $this->buildState($attemptId);
    }

    public function result(int $userId, int $examId, int $attemptId): array
    {
        $student = $this->resolveStudent($userId);
        $attempt = $this->ownedAttempt($student['id'], $examId, $attemptId);
        $this->finalizeIfPastGrace($attempt);

        $attempt = $this->attempts->find($attemptId);
        if ($attempt['status'] === C::ATTEMPT_IN_PROGRESS) {
            throw new AppException('The attempt is still running.', 409, C::ERR_INVALID_STATE);
        }
        $exam = $this->exams->find($examId, true);
        [$rows, $choices, $answers] = $this->attemptGraph($attemptId);
        return $this->presenter->attemptResult($exam, $attempt, $rows, $choices, $answers);
    }

    // =============================================================== start

    /** Starts the student's single attempt, or returns the existing one (DEC-1). Idempotent. */
    public function start(int $userId, int $examId, array $body = []): array
    {
        $student = $this->resolveStudent($userId);
        $now = $this->clock->now();
        $nowDb = $now->format(Clock::DB_FORMAT);

        $exam = $this->exams->findVisibleForStudent($examId, $student['id'], $student['stage_id'], $nowDb);
        if (!$exam) {
            throw new NotFoundException('Game exam');
        }

        $existing = $this->attempts->findByExamAndStudent($examId, $student['id']);
        if ($existing) {
            $this->finalizeIfPastGrace($existing);
            return $this->buildState((int) $existing['id']);
        }

        if (!$this->isStartable($exam, $student['stage_id'])) {
            throw new AppException('This exam is not available right now.', 409, C::ERR_EXAM_NOT_AVAILABLE);
        }

        $questions = $this->exams->questions($examId);
        if ($questions === []) {
            throw new AppException('This exam has no questions.', 409, C::ERR_EXAM_NOT_AVAILABLE);
        }

        $deviceId = isset($body['device_id']) && is_string($body['device_id']) ? mb_substr(trim($body['device_id']), 0, 100) : null;
        $maxPoints = array_sum(array_map(fn($q) => (int) $q['score'], $questions));

        $this->db->beginTransaction();
        try {
            $attemptId = $this->attempts->create([
                'exam_id' => $examId,
                'student_id' => $student['id'],
                'stage_id' => $student['stage_id'],
                'status' => C::ATTEMPT_IN_PROGRESS,
                'started_at' => $nowDb,
                'expires_at' => $now->modify('+' . (int) $exam['duration_seconds'] . ' seconds')->format(Clock::DB_FORMAT),
                'max_points' => $maxPoints,
                'pass_percentage' => $exam['pass_percentage'],
                'reveal_policy' => $exam['reveal_policy'],
                'offline_grace_seconds' => (int) $exam['offline_grace_seconds'],
                'device_id' => $deviceId,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
            ]);

            foreach ($questions as $i => $q) {
                $choiceIds = array_map(fn($c) => (int) $c['id'], $q['choices']);
                if ((int) $exam['shuffle_choices'] === 1) {
                    shuffle($choiceIds);
                }
                $this->attempts->insertAttemptQuestion([
                    'attempt_id' => $attemptId,
                    'question_id' => (int) $q['id'],
                    'position' => $i + 1,
                    'choice_order' => json_encode($choiceIds),
                    'state' => C::Q_UNANSWERED,
                ]);
            }
            $this->exams->incrementAttempts($examId);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            // Two devices pressed "start" together: uq_gea_exam_student lets one
            // win; the loser simply resumes the winner's attempt.
            if ($e->getCode() === '23000') {
                $existing = $this->attempts->findByExamAndStudent($examId, $student['id']);
                if ($existing) {
                    return $this->buildState((int) $existing['id']);
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info('Game exam attempt started', ['attempt_id' => $attemptId, 'exam_id' => $examId, 'student_id' => $student['id']]);
        return $this->buildState($attemptId);
    }

    // ============================================================== answer

    /**
     * Online single answer. Domain rejections (already answered, retry gate,
     * expiry...) are returned as `result.status = rejected` together with
     * the authoritative state so the client can reconcile in one round trip.
     */
    public function answer(int $userId, int $examId, int $attemptId, array $body): array
    {
        $student = $this->resolveStudent($userId);
        $action = $this->validateAction($body, 'body');
        $this->ownedAttempt($student['id'], $examId, $attemptId);

        // Fast path: a retry of a request the server already processed.
        $replay = $this->replay($attemptId, $action['client_action_id']);
        if ($replay !== null) {
            return ['result' => $replay, 'state' => $this->buildState($attemptId)];
        }

        $now = $this->clock->now();
        $this->db->beginTransaction();
        try {
            $attempt = $this->attempts->findForUpdate($attemptId);
            $result = $this->guardAttemptOpen($attempt, $action, $now, false)
                ?? $this->replay($attemptId, $action['client_action_id'])
                ?? $this->processAction($attempt, $action, $now, $now, false);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Game answer failed', ['attempt_id' => $attemptId, 'action' => $action['client_action_id'], 'error' => $e->getMessage()]);
            throw $e;
        }

        return ['result' => $result, 'state' => $this->buildState($attemptId)];
    }

    // ================================================================ sync

    /**
     * Applies a batch of queued (offline / unconfirmed) actions in `seq`
     * order inside ONE transaction holding the attempt lock. Each action is
     * answered individually (applied | replayed | rejected); one rejection
     * never blocks the rest.
     */
    public function sync(int $userId, int $examId, int $attemptId, array $body): array
    {
        $student = $this->resolveStudent($userId);
        $this->ownedAttempt($student['id'], $examId, $attemptId);

        $raw = $body['actions'] ?? null;
        if (!is_array($raw)) {
            throw new ValidationException(['actions' => 'actions must be an array']);
        }
        if (count($raw) > C::SYNC_MAX_ACTIONS) {
            throw new AppException('Too many actions in one sync request.', 413, C::ERR_SYNC_TOO_LARGE, ['max' => C::SYNC_MAX_ACTIONS]);
        }
        $actions = [];
        foreach (array_values($raw) as $i => $a) {
            $actions[] = $this->validateAction(is_array($a) ? $a : [], "actions.$i");
        }
        usort($actions, fn($x, $y) => $x['seq'] <=> $y['seq']);

        $now = $this->clock->now();
        $results = [];

        $this->db->beginTransaction();
        try {
            foreach ($actions as $action) {
                $attempt = $this->attempts->findForUpdate($attemptId);

                $replay = $this->replay($attemptId, $action['client_action_id']);
                if ($replay !== null) {
                    $results[] = $replay;
                    continue;
                }

                $guard = $this->guardAttemptOpen($attempt, $action, $now, true);
                if ($guard !== null) {
                    $results[] = $guard;
                    continue;
                }

                $answeredAt = $this->trustedOfflineTime($attempt, $action, $now);
                if ($answeredAt === null) {
                    $results[] = $this->rejection($action, C::CODE_ANSWERED_AFTER_EXPIRY);
                    continue;
                }

                $results[] = $this->processAction($attempt, $action, $answeredAt, $now, true);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Game sync failed', ['attempt_id' => $attemptId, 'error' => $e->getMessage()]);
            throw $e;
        }

        $this->logger->info('Game sync processed', [
            'attempt_id' => $attemptId,
            'actions' => count($actions),
            'applied' => count(array_filter($results, fn($r) => $r['status'] === C::ACTION_APPLIED)),
        ]);

        return ['results' => $results, 'state' => $this->buildState($attemptId)];
    }

    // ============================================================ complete

    /** Student ends the exam (or the client noticed expiry). Idempotent. */
    public function complete(int $userId, int $examId, int $attemptId): array
    {
        $student = $this->resolveStudent($userId);
        $this->ownedAttempt($student['id'], $examId, $attemptId);
        $now = $this->clock->now();

        $this->db->beginTransaction();
        try {
            $attempt = $this->attempts->findForUpdate($attemptId);
            if ($attempt['status'] === C::ATTEMPT_IN_PROGRESS) {
                $expired = $now > Clock::parseDb($attempt['expires_at']);
                $this->finalize($attempt, $expired ? C::ATTEMPT_EXPIRED : C::ATTEMPT_COMPLETED, $now);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->buildState($attemptId);
    }

    /** Cron/maintenance: finalizes attempts abandoned past expiry + grace. */
    public function expireStale(int $limit = 200): int
    {
        $ids = $this->attempts->expiredOpenAttemptIds($this->clock->nowDb(), $limit);
        $done = 0;
        foreach ($ids as $id) {
            $attempt = $this->attempts->find($id);
            if ($attempt && $this->finalizeIfPastGrace($attempt)) {
                $done++;
            }
        }
        return $done;
    }

    // ============================================================ internals

    /**
     * Scores one action. Caller holds the transaction and the attempt lock.
     * @param DateTimeImmutable $answeredAt trusted answer time (retry-gate check)
     * @param DateTimeImmutable $now server processing time
     */
    private function processAction(array $attempt, array $action, DateTimeImmutable $answeredAt, DateTimeImmutable $now, bool $offline): array
    {
        $attemptId = (int) $attempt['id'];
        $aq = $this->attempts->attemptQuestionForUpdate($attemptId, $action['question_id']);
        if (!$aq) {
            return $this->rejection($action, C::CODE_INVALID_QUESTION);
        }
        $choice = $this->attempts->findChoice($action['question_id'], $action['choice_id']);
        if (!$choice) {
            return $this->rejection($action, C::CODE_INVALID_CHOICE);
        }

        $outcome = $this->policy->evaluate(
            $aq,
            [
                'state' => $aq['state'],
                'attempts_used' => $aq['attempts_used'],
                'retry_available_at' => Clock::parseDb($aq['retry_available_at']),
            ],
            (int) $choice['is_correct'] === 1,
            $answeredAt,
            $action['expected_attempt_no']
        );

        if (!$outcome['ok']) {
            $extra = [];
            if (isset($outcome['retry_available_at'])) {
                $extra['retry_available_at'] = $outcome['retry_available_at']->format(Clock::API_FORMAT);
            }
            return $this->rejection($action, $outcome['code'], $extra);
        }

        // An offline first wrong answer: the student only learns about it
        // now, so the retry gate starts at server processing time.
        $retryAt = $outcome['retry_available_at'];
        if ($retryAt !== null && $offline) {
            $retryAt = $now->modify('+' . (int) $aq['retry_seconds'] . ' seconds');
        }

        $nowDb = $now->format(Clock::DB_FORMAT);
        $result = [
            'client_action_id' => $action['client_action_id'],
            'status' => C::ACTION_APPLIED,
            'code' => null,
            'question_id' => $action['question_id'],
            'attempt_no' => $outcome['attempt_no'],
            'choice_id' => $action['choice_id'],
            'is_correct' => $outcome['is_correct'],
            'points_delta' => $outcome['points_delta'],
            'question_state' => $outcome['new_state'],
            'question_result' => $outcome['result'],
            'retry_available_at' => $retryAt?->format(Clock::API_FORMAT),
        ];

        try {
            $answerId = $this->attempts->insertAnswer([
                'attempt_id' => $attemptId,
                'question_id' => $action['question_id'],
                'attempt_no' => $outcome['attempt_no'],
                'choice_id' => $action['choice_id'],
                'is_correct' => $outcome['is_correct'] ? 1 : 0,
                'points_delta' => $outcome['points_delta'],
                'client_action_id' => $action['client_action_id'],
                'client_answered_at' => $action['client_answered_at']?->format(Clock::DB_FORMAT),
                'answered_at' => $answeredAt->format(Clock::DB_FORMAT),
                'was_offline' => $offline ? 1 : 0,
                'response_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'created_at' => $nowDb,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Unreachable while the attempt lock is held, kept as a last guard.
            return $this->replay($attemptId, $action['client_action_id'])
                ?? $this->rejection($action, C::CODE_SLOT_TAKEN);
        }

        $this->attempts->updateAttemptQuestion((int) $aq['id'], [
            'state' => $outcome['new_state'],
            'result' => $outcome['result'],
            'attempts_used' => $outcome['attempt_no'],
            'retry_available_at' => $retryAt?->format(Clock::DB_FORMAT),
            'earned_points' => (int) $aq['earned_points'] + $outcome['earned'],
            'deducted_points' => (int) $aq['deducted_points'] + $outcome['deducted'],
            'completed_at' => $outcome['completed'] ? $nowDb : null,
        ]);

        $this->attempts->incrementTotals(
            $attemptId,
            $outcome['earned'],
            $outcome['deducted'],
            $outcome['completed'] && $outcome['is_correct'] ? 1 : 0,
            $outcome['result'] === C::RESULT_WRONG_FINAL ? 1 : 0
        );

        if ($outcome['points_delta'] !== 0) {
            $added = $this->ledger->add(
                (int) $attempt['student_id'],
                (int) $attempt['stage_id'],
                $outcome['points_delta'],
                C::LEDGER_SOURCE_GAME_ANSWER,
                $answerId,
                (int) $attempt['exam_id'],
                $nowDb
            );
            if ($added) {
                $this->ledger->applyToStudent((int) $attempt['student_id'], $outcome['points_delta'], C::STUDENT_POINTS_FLOOR);
            }
        }

        if ($this->attempts->openQuestionCount($attemptId) === 0) {
            $this->finalize($this->attempts->findForUpdate($attemptId), C::ATTEMPT_COMPLETED, $now);
        }

        return $result;
    }

    /**
     * Rejects actions on attempts that can no longer take answers. Also
     * finalizes an attempt that is past expiry + grace.
     */
    private function guardAttemptOpen(array $attempt, array $action, DateTimeImmutable $now, bool $offline): ?array
    {
        if ($attempt['status'] !== C::ATTEMPT_IN_PROGRESS) {
            return $this->rejection($action, $attempt['status'] === C::ATTEMPT_EXPIRED ? C::CODE_ATTEMPT_EXPIRED : C::CODE_ATTEMPT_COMPLETED);
        }
        $expires = Clock::parseDb($attempt['expires_at']);
        $graceEnd = $expires->modify('+' . (int) $attempt['offline_grace_seconds'] . ' seconds');

        if ($now > $graceEnd) {
            $this->finalize($attempt, C::ATTEMPT_EXPIRED, $now);
            return $this->rejection($action, $offline ? C::CODE_ANSWERED_AFTER_EXPIRY : C::CODE_ATTEMPT_EXPIRED);
        }
        if (!$offline && $now > $expires) {
            return $this->rejection($action, C::CODE_ATTEMPT_EXPIRED);
        }
        return null;
    }

    /**
     * Trusted time of an offline answer: the device's claim, accepted only
     * inside [started_at - skew, expires_at] and never in the future.
     */
    private function trustedOfflineTime(array $attempt, array $action, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $started = Clock::parseDb($attempt['started_at']);
        $expires = Clock::parseDb($attempt['expires_at']);
        $claimed = $action['client_answered_at'] ?? $now;

        if ($claimed > $now) {
            $claimed = $now;
        }
        if ($claimed > $expires) {
            return null;
        }
        if ($claimed < $started->modify('-' . C::CLIENT_CLOCK_SKEW_SECONDS . ' seconds')) {
            return null;
        }
        return $claimed < $started ? $started : $claimed;
    }

    /** Caller holds the transaction and the attempt lock. */
    private function finalize(array $attempt, string $status, DateTimeImmutable $now): void
    {
        $attemptId = (int) $attempt['id'];
        $nowDb = $now->format(Clock::DB_FORMAT);

        $this->attempts->closeOpenQuestions($attemptId, $nowDb);
        $counts = $this->attempts->resultCounts($attemptId);
        $fresh = $this->attempts->find($attemptId);

        $totals = $this->policy->finalize(
            (int) $fresh['max_points'],
            (int) $fresh['earned_points'],
            (int) $fresh['deducted_points'],
            (float) $fresh['pass_percentage']
        );

        $finishedAt = $status === C::ATTEMPT_EXPIRED ? min($nowDb, $fresh['expires_at']) : $nowDb;

        $this->attempts->update($attemptId, [
            'status' => $status,
            'finished_at' => $finishedAt,
            'final_points' => $totals['final_points'],
            'percentage' => $totals['percentage'],
            'is_passed' => $totals['is_passed'] ? 1 : 0,
            'grade_key' => $totals['grade_key'],
            'grade_label' => $totals['grade_label'],
            'correct_count' => $counts['correct'],
            'failed_count' => $counts['failed'],
            'sync_version' => (int) $fresh['sync_version'] + 1,
            'updated_at' => $nowDb,
        ]);

        $this->logger->info('Game attempt finalized', ['attempt_id' => $attemptId, 'status' => $status, 'final_points' => $totals['final_points']]);
    }

    /** @return bool true if this call finalized the attempt */
    private function finalizeIfPastGrace(array $attempt): bool
    {
        if ($attempt['status'] !== C::ATTEMPT_IN_PROGRESS) {
            return false;
        }
        $now = $this->clock->now();
        $graceEnd = Clock::parseDb($attempt['expires_at'])->modify('+' . (int) $attempt['offline_grace_seconds'] . ' seconds');
        if ($now <= $graceEnd) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $locked = $this->attempts->findForUpdate((int) $attempt['id']);
            $finalized = false;
            if ($locked['status'] === C::ATTEMPT_IN_PROGRESS) {
                $this->finalize($locked, C::ATTEMPT_EXPIRED, $now);
                $finalized = true;
            }
            $this->db->commit();
            return $finalized;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function replay(int $attemptId, string $clientActionId): ?array
    {
        $row = $this->attempts->answerByAction($attemptId, $clientActionId);
        if (!$row) {
            return null;
        }
        $snapshot = json_decode($row['response_snapshot'], true) ?: [];
        $snapshot['status'] = C::ACTION_REPLAYED;
        return $snapshot;
    }

    private function rejection(array $action, string $code, array $extra = []): array
    {
        return array_merge([
            'client_action_id' => $action['client_action_id'],
            'status' => C::ACTION_REJECTED,
            'code' => $code,
            'question_id' => $action['question_id'],
            'attempt_no' => $action['expected_attempt_no'],
            'choice_id' => $action['choice_id'],
        ], $extra);
    }

    private function ownedAttempt(int $studentId, int $examId, int $attemptId): array
    {
        $attempt = $this->attempts->find($attemptId);
        // 404 (not 403) so attempt ids of other students are not discoverable.
        if (!$attempt || (int) $attempt['student_id'] !== $studentId || (int) $attempt['exam_id'] !== $examId) {
            throw new NotFoundException('Attempt');
        }
        return $attempt;
    }

    private function isStartable(array $exam, int $stageId): bool
    {
        $now = $this->clock->nowDb();
        return $exam['status'] === C::STATUS_PUBLISHED
            && $exam['deleted_at'] === null
            && (int) $exam['stage_id'] === $stageId
            && ($exam['available_from'] === null || $exam['available_from'] <= $now)
            && ($exam['available_until'] === null || $exam['available_until'] > $now);
    }

    private function buildState(int $attemptId): array
    {
        $attempt = $this->attempts->find($attemptId);
        [$rows, $choices, $answers] = $this->attemptGraph($attemptId);
        return $this->presenter->attemptState($attempt, $rows, $choices, $answers, $this->clock->now()->format(Clock::API_FORMAT));
    }

    /** @return array{0: array, 1: array, 2: array} */
    private function attemptGraph(int $attemptId): array
    {
        $rows = $this->attempts->attemptQuestions($attemptId);
        $choices = $this->attempts->choicesForQuestions(array_map(fn($r) => (int) $r['question_id'], $rows));
        $answers = $this->attempts->answersForAttempt($attemptId);
        return [$rows, $choices, $answers];
    }

    /** @return array{question_id:int, choice_id:int, client_action_id:string, expected_attempt_no:int, client_answered_at:?DateTimeImmutable, seq:int} */
    private function validateAction(array $a, string $p): array
    {
        $errors = [];
        $qid = $a['question_id'] ?? null;
        $cid = $a['choice_id'] ?? null;
        $uuid = $a['client_action_id'] ?? null;
        $expected = $a['expected_attempt_no'] ?? null;

        if (!is_numeric($qid) || (int) $qid < 1) {
            $errors["$p.question_id"] = 'question_id is required';
        }
        if (!is_numeric($cid) || (int) $cid < 1) {
            $errors["$p.choice_id"] = 'choice_id is required';
        }
        if (!is_string($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $errors["$p.client_action_id"] = 'client_action_id must be a UUID';
        }
        if (!in_array((int) $expected, [1, 2], true) || !is_numeric($expected)) {
            $errors["$p.expected_attempt_no"] = 'expected_attempt_no must be 1 or 2';
        }
        $clientAt = null;
        if (isset($a['client_answered_at']) && $a['client_answered_at'] !== null) {
            $clientAt = Clock::parseIso((string) $a['client_answered_at']);
            if ($clientAt === null) {
                $errors["$p.client_answered_at"] = 'client_answered_at must be ISO-8601';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'question_id' => (int) $qid,
            'choice_id' => (int) $cid,
            'client_action_id' => strtolower($uuid),
            'expected_attempt_no' => (int) $expected,
            'client_answered_at' => $clientAt,
            'seq' => isset($a['seq']) && is_numeric($a['seq']) ? (int) $a['seq'] : 0,
        ];
    }
}
