<?php

namespace App\Controllers;

use App\Services\GameExamPlayService;
use App\Support\ListQuery as Q;

/**
 * Student endpoints (['auth','student']). The student is always resolved
 * from the JWT (`auth_user_id`) — never from request data.
 */
class StudentGameExamController
{
    private GameExamPlayService $play;

    public function __construct(GameExamPlayService $play)
    {
        $this->play = $play;
    }

    // GET /student/game-exams?filter=all|attempted|not_attempted|in_progress|passed|failed&search=
    public function index(): array
    {
        $q = $_GET;
        $limit = Q::limit($q);
        $offset = Q::offset($q);
        $filters = [
            'search' => Q::str($q, 'search'),
            'filter' => Q::oneOf($q, 'filter', ['all', 'attempted', 'not_attempted', 'in_progress', 'passed', 'failed'], 'all'),
        ];
        $page = $this->play->listExams(Q::authUserId(), $filters, $limit, $offset);
        return ['success' => true, 'data' => $page['data'], 'meta' => $page['meta']];
    }

    // GET /student/game-exams/{id}
    public function show(string $id): array
    {
        return ['success' => true, 'data' => $this->play->showExam(Q::authUserId(), (int) $id)];
    }

    // POST /student/game-exams/{id}/attempts
    public function start(string $id, array $body = []): array
    {
        return ['success' => true, 'data' => $this->play->start(Q::authUserId(), (int) $id, $body)];
    }

    // GET /student/game-exams/{id}/attempts/{attemptId}
    public function attempt(string $id, string $attemptId): array
    {
        return ['success' => true, 'data' => $this->play->state(Q::authUserId(), (int) $id, (int) $attemptId)];
    }

    // POST /student/game-exams/{id}/attempts/{attemptId}/answers
    public function answer(string $id, string $attemptId, array $body): array
    {
        return ['success' => true, 'data' => $this->play->answer(Q::authUserId(), (int) $id, (int) $attemptId, $body)];
    }

    // POST /student/game-exams/{id}/attempts/{attemptId}/sync
    public function sync(string $id, string $attemptId, array $body): array
    {
        return ['success' => true, 'data' => $this->play->sync(Q::authUserId(), (int) $id, (int) $attemptId, $body)];
    }

    // POST /student/game-exams/{id}/attempts/{attemptId}/complete
    public function complete(string $id, string $attemptId, array $body = []): array
    {
        return ['success' => true, 'data' => $this->play->complete(Q::authUserId(), (int) $id, (int) $attemptId)];
    }

    // GET /student/game-exams/{id}/attempts/{attemptId}/result
    public function result(string $id, string $attemptId): array
    {
        return ['success' => true, 'data' => $this->play->result(Q::authUserId(), (int) $id, (int) $attemptId)];
    }
}
