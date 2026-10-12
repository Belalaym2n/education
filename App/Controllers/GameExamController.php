<?php

namespace App\Controllers;

use App\Config\GameExamConstants as C;
use App\Services\GameExamResultsService;
use App\Services\GameExamService;
use App\Support\CairoMonth;
use App\Support\ListQuery as Q;

/**
 * Dashboard endpoints (['auth','admin']). Route params arrive positionally;
 * for POST/PUT the decoded JSON body is the LAST argument (see Router).
 */
class GameExamController
{
    private GameExamService $service;
    private GameExamResultsService $results;

    public function __construct(GameExamService $service, GameExamResultsService $results)
    {
        $this->service = $service;
        $this->results = $results;
    }

    // GET /game-exams
    public function index(): array
    {
        $q = $_GET;
        $limit = Q::limit($q);
        $offset = Q::offset($q);
        $page = $this->service->list($this->examFilters($q), $limit, $offset, Q::str($q, 'sort') ?? 'created_at', Q::order($q));
        return ['success' => true, 'data' => $page['data'], 'meta' => $page['meta']];
    }

    // GET /game-exams/statistics
    public function statistics(): array
    {
        return ['success' => true, 'data' => $this->service->statistics($this->examFilters($_GET))];
    }

    // GET /game-exams/top-students?month=YYYY-MM&stage_id=&limit=
    public function topStudents(): array
    {
        $q = $_GET;
        return [
            'success' => true,
            'data' => $this->results->topStudents(Q::str($q, 'month', 7), Q::int($q, 'stage_id'), Q::int($q, 'limit')),
        ];
    }

    // GET /game-exams/{id}
    public function show(string $id): array
    {
        return ['success' => true, 'data' => $this->service->show((int) $id)];
    }

    // POST /game-exams
    public function store(array $body): array
    {
        return ['success' => true, 'data' => $this->service->create($body, Q::authUserId()), 'message' => 'تم إنشاء الاختبار'];
    }

    // PUT /game-exams/{id}
    public function update(string $id, array $body): array
    {
        return ['success' => true, 'data' => $this->service->update((int) $id, $body, Q::authUserId()), 'message' => 'تم حفظ الاختبار'];
    }

    // DELETE /game-exams/{id}
    public function destroy(string $id): array
    {
        $this->service->delete((int) $id, Q::authUserId());
        return ['success' => true, 'message' => 'تم حذف الاختبار'];
    }

    // POST /game-exams/{id}/restore
    public function restore(string $id, array $body = []): array
    {
        return ['success' => true, 'data' => $this->service->restore((int) $id, Q::authUserId()), 'message' => 'تم استرجاع الاختبار'];
    }

    // POST /game-exams/{id}/publish
    public function publish(string $id, array $body = []): array
    {
        return ['success' => true, 'data' => $this->service->publish((int) $id, Q::authUserId()), 'message' => 'تم نشر الاختبار'];
    }

    // POST /game-exams/{id}/unpublish
    public function unpublish(string $id, array $body = []): array
    {
        return ['success' => true, 'data' => $this->service->unpublish((int) $id, Q::authUserId()), 'message' => 'تم إلغاء نشر الاختبار'];
    }

    // POST /game-exams/{id}/duplicate
    public function duplicate(string $id, array $body = []): array
    {
        return ['success' => true, 'data' => $this->service->duplicate((int) $id, Q::authUserId()), 'message' => 'تم نسخ الاختبار'];
    }

    // PUT /game-exams/{id}/questions/order
    public function reorder(string $id, array $body): array
    {
        return ['success' => true, 'data' => $this->service->reorder((int) $id, $body, Q::authUserId())];
    }

    // GET /game-exams/{id}/results
    public function results(string $id): array
    {
        $q = $_GET;
        $limit = Q::limit($q);
        $offset = Q::offset($q);
        $page = $this->results->results((int) $id, $this->resultFilters($q), $limit, $offset, Q::str($q, 'sort') ?? 'started_at', Q::order($q));
        return ['success' => true, 'data' => $page['data'], 'meta' => $page['meta']];
    }

    // GET /game-exams/{id}/results/statistics
    public function resultStatistics(string $id): array
    {
        return ['success' => true, 'data' => $this->results->statistics((int) $id, $this->resultFilters($_GET))];
    }

    // GET /game-exams/{id}/results/{attemptId}
    public function resultDetail(string $id, string $attemptId): array
    {
        return ['success' => true, 'data' => $this->results->attemptDetail((int) $id, (int) $attemptId)];
    }

    private function examFilters(array $q): array
    {
        $dates = Q::dateRange($q);
        return [
            'search' => Q::str($q, 'search'),
            'stage_id' => Q::int($q, 'stage_id'),
            'status' => Q::oneOf($q, 'status', C::STATUSES),
            'deleted' => Q::oneOf($q, 'deleted', ['exclude', 'include', 'only'], 'exclude'),
            'date_field' => Q::oneOf($q, 'date_field', ['created_at', 'updated_at', 'published_at'], 'created_at'),
            'date_from_utc' => $dates['from_utc'],
            'date_to_utc' => $dates['to_utc'],
            'min_questions' => Q::int($q, 'min_questions'),
            'max_questions' => Q::int($q, 'max_questions'),
            'created_by' => Q::int($q, 'created_by'),
        ];
    }

    private function resultFilters(array $q): array
    {
        $dates = Q::dateRange($q);
        $from = $dates['from_utc'];
        $to = $dates['to_utc'];

        // month=YYYY-MM (Cairo) or year=YYYY narrow the started_at range.
        if ($month = Q::str($q, 'month', 7)) {
            $range = CairoMonth::range($month);
            $from = $range['from'];
            $to = $range['to'];
        } elseif (($year = Q::int($q, 'year')) !== null && $year >= 2000 && $year <= 2100) {
            $from = CairoMonth::range(sprintf('%04d-01', $year))['from'];
            $to = CairoMonth::range(sprintf('%04d-01', $year + 1))['from'];
        }

        return [
            'search' => Q::str($q, 'search'),
            'status' => Q::oneOf($q, 'status', C::ATTEMPT_STATUSES),
            'is_passed' => Q::boolOrNull($q, 'is_passed'),
            'from_utc' => $from,
            'to_utc' => $to,
        ];
    }
}
