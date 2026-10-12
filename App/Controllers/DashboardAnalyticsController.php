<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Helpers\ResponseHelper;
use App\Services\DashboardAnalyticsService;

/**
 * Teacher dashboard analytics (admin role only — see routes.php).
 *
 * The data scope comes from the authenticated role, never from the client:
 * no teacher/user id is accepted as a filter.
 */
class DashboardAnalyticsController
{
    public function __construct(private DashboardAnalyticsService $service) {}

    /** GET /api/admin/analytics/overview?period=today|week|month|year|custom&from=&to=&stage_id= */
    public function overview(): array
    {
        $period = isset($_GET['period']) && $_GET['period'] !== '' ? (string) $_GET['period'] : 'month';
        $from = $period === 'custom' ? $this->dateParam('from') : null;
        $to = $period === 'custom' ? $this->dateParam('to') : null;

        return ResponseHelper::success($this->service->overview(
            (int) ($_SERVER['auth_user_id'] ?? 0),
            $period,
            $from,
            $to,
            $this->stageParam()
        ));
    }

    /** GET /api/admin/analytics/activity?limit=15&stage_id= */
    public function activity(): array
    {
        $limit = 15;
        if (isset($_GET['limit']) && $_GET['limit'] !== '') {
            $limit = filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
            if ($limit === false) {
                throw new ValidationException(['limit' => 'limit must be an integer between 1 and 50']);
            }
        }

        return ResponseHelper::success($this->service->activity($this->stageParam(), $limit));
    }

    private function stageParam(): ?int
    {
        if (!isset($_GET['stage_id']) || $_GET['stage_id'] === '') {
            return null;
        }
        $id = filter_var($_GET['stage_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ValidationException(['stage_id' => 'stage_id must be a positive integer']);
        }
        return $id;
    }

    private function dateParam(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
