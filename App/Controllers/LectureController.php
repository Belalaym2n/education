<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Exceptions\AuthException;
use App\Services\LectureService;
use App\Validators\Validator;

class LectureController
{
    private LectureService $lectureService;

    private const ADMIN_ROLE_ID = '3';

    public function __construct(LectureService $lectureService)
    {
        $this->lectureService = $lectureService;
    }

    // =========================================================
    // GET /lectures  (role-aware: admin sees all, student sees own stage)
    // =========================================================
    public function index(): array
    {
        [$limit, $offset, $sort, $order] = $this->getPaginationParams();
        $search = isset($_GET['search']) ? trim((string) $_GET['search']) : null;

        if ($this->isAdmin()) { 
            $filters = ['search' => $search];

            if (isset($_GET['stage_id']) && $_GET['stage_id'] !== '') {
                $filters['stage_id'] = (int) $_GET['stage_id'];
            }
            if (isset($_GET['is_published']) && $_GET['is_published'] !== '') {
                $filters['is_published'] = (int) $_GET['is_published'];
            }

            $result = $this->lectureService->listForAdmin($filters, $limit, $offset, $sort, $order);
        } else {
            $authUserId = $this->getAuthUserId();
            $filters = ['search' => $search];

            $result = $this->lectureService->listForStudent($authUserId, $filters, $limit, $offset, $sort, $order);
        }

        return [
            'success' => true,
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'],
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'order' => $order,
            ],
        ];
    }

    // =========================================================
    // GET /lectures/{id}
    // =========================================================
    public function show(int $id): array
    {
        if ($this->isAdmin()) {
            $lecture = $this->lectureService->getLectureForAdmin($id);
        } else {
            $authUserId = $this->getAuthUserId();
            $lecture = $this->lectureService->getLectureForStudent($authUserId, $id);
        }

        return ['success' => true, 'data' => $lecture];
    }

    // =========================================================
    // POST /lectures  (admin only — enforced by route middleware)
    // =========================================================
    public function store(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('stage_id');
        $validator->required('title');
        $validator->required('youtube_url');

        $validator->validateNumber('stage_id', 1);
        $validator->validateString('title', 3, 200);
        $validator->validateYoutubeURL('youtube_url');

        if (isset($requestData['description'])) {
            $validator->validateString('description', 0, 65535);
        }
        if (isset($requestData['thumbnail_url']) && $requestData['thumbnail_url'] !== '') {
            $validator->validateURL('thumbnail_url');
        }
        if (isset($requestData['duration_seconds']) && $requestData['duration_seconds'] !== '') {
            $validator->validateNumber('duration_seconds', 1);
        }
        if (isset($requestData['order_number']) && $requestData['order_number'] !== '') {
            $validator->validateNumber('order_number', 0);
        }
        if (isset($requestData['price'])) {
            $validator->validateNumber('price', 0);
        }

        if (isset($requestData['is_free'])) {
            $validator->validateBoolean('is_free', false);
        }

        // Business Rules
        if (isset($requestData['price']) && (float)$requestData['price'] == 0.0) {
            $requestData['is_free'] = true;
        }

        if (isset($requestData['is_free']) && $requestData['is_free']) {
            $requestData['price'] = 0;
        }

        if (
            isset($requestData['price']) &&
            (float)$requestData['price'] > 0
        ) {
            $requestData['is_free'] = false;
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }


        $createdBy = $this->getAuthUserId();

        $lecture = $this->lectureService->createLecture($requestData, $createdBy);
        return ['success' => true, 'data' => $lecture, 'message' => 'Lecture created successfully'];
    }

    // =========================================================
    // PUT /lectures/{id}  (admin only)
    // =========================================================
    public function update(int $id, array $requestData): array
    {
        $validator = new Validator($requestData);

        if (isset($requestData['stage_id'])) {
            $validator->validateNumber('stage_id', 1);
        }
        if (isset($requestData['title'])) {
            $validator->validateString('title', 3, 200);
        }
        if (isset($requestData['youtube_url'])) {
            $validator->validateYoutubeURL('youtube_url');
        }
        if (isset($requestData['description'])) {
            $validator->validateString('description', 0, 65535);
        }
        if (isset($requestData['thumbnail_url']) && $requestData['thumbnail_url'] !== '') {
            $validator->validateURL('thumbnail_url');
        }
        if (isset($requestData['duration_seconds']) && $requestData['duration_seconds'] !== '') {
            $validator->validateNumber('duration_seconds', 1);
        }
        if (isset($requestData['order_number']) && $requestData['order_number'] !== '') {
            $validator->validateNumber('order_number', 0);
        }
        if (isset($requestData['price'])) {
            $validator->validateNumber('price', 0);
        }
        if (isset($requestData['is_free'])) {
            $validator->validateBoolean('is_free', false);
        }

         if (isset($requestData['max_views'])) {
            $validator->validateNumber('max_views', 0);
        }
        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $this->extractPayload($requestData);

        $lecture = $this->lectureService->updateLecture($id, $clean);

        return ['success' => true, 'data' => $lecture, 'message' => 'Lecture updated successfully'];
    }

    // =========================================================
    // DELETE /lectures/{id}  (admin only)
    // =========================================================
    public function destroy(int $id): array
    {
        $this->lectureService->deleteLecture($id);

        return ['success' => true, 'message' => 'Lecture deleted successfully'];
    }

    // =========================================================
    // PATCH /lectures/{id}/publish  (admin only)
    // =========================================================
    public function publish(int $id): array
    {
        $lecture = $this->lectureService->publishLecture($id);

        return ['success' => true, 'data' => $lecture, 'message' => 'Lecture published successfully'];
    }

    // =========================================================
    // PATCH /lectures/{id}/unpublish  (admin only)
    // =========================================================
    public function unpublish(int $id): array
    {
        $lecture = $this->lectureService->unpublishLecture($id);

        return ['success' => true, 'data' => $lecture, 'message' => 'Lecture unpublished successfully'];
    }

    // =========================================================
    // PATCH /lectures/reorder  (admin only)
    // Body: { "stage_id": 2, "items": [ {"id": 10, "order_number": 1}, {"id": 11, "order_number": 2} ] }
    // =========================================================
    public function reorder(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('stage_id');
        $validator->validateNumber('stage_id', 1);

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        if (!isset($requestData['items']) || !is_array($requestData['items']) || empty($requestData['items'])) {
            throw new ValidationException(['items' => 'items must be a non-empty array of {id, order_number}']);
        }

        foreach ($requestData['items'] as $index => $item) {
            if (!is_array($item) || !isset($item['id'], $item['order_number'])) {
                throw new ValidationException([
                    "items.$index" => 'Each item must contain id and order_number',
                ]);
            }
            if (!is_numeric($item['id']) || !is_numeric($item['order_number'])) {
                throw new ValidationException([
                    "items.$index" => 'id and order_number must be numeric',
                ]);
            }
        }
 
        $result = $this->lectureService->reorderLectures(
            (int) $requestData['stage_id'],
            $requestData['items']
        );

        return ['success' => true, 'data' => $result, 'message' => 'Lectures reordered successfully'];
    }

    // =========================================================
    // INTERNAL HELPERS
    // =========================================================

    private function extractPayload(array $requestData): array
    {
        $fields = [
            'stage_id',
            'title',
            'description',
            'youtube_url',
            'thumbnail_url',
            'duration_seconds',
            'order_number',
            'price',
            'is_free',
            'max_views',
        ];

        $payload = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $requestData) && $requestData[$field] !== '') {
                $payload[$field] = $requestData[$field];
            } 
        }

        // title/description need HTML-safe sanitization; numeric/bool fields are cast, not stripped.
        if (isset($payload['title'])) {
            $payload['title'] = trim(strip_tags($payload['title']));
        }
        if (isset($payload['description'])) {
            $payload['description'] = trim(strip_tags($payload['description']));
        }

        return $payload;
    }

    private function getPaginationParams(): array
    {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $sort = isset($_GET['sort']) ? (string) $_GET['sort'] : 'created_at';
        $order = isset($_GET['order']) ? (string) $_GET['order'] : 'desc';

        // Invalid pagination/sort input never throws — falls back to safe defaults,
        // final whitelist enforcement happens again in the Repository.
        if ($limit < 1 || $limit > 200) {
            $limit = 20;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        return [$limit, $offset, $sort, $order];
    }

    private function isAdmin(): bool
    {
        return ($_SERVER['auth_role'] ?? null) == self::ADMIN_ROLE_ID;
    }

    private function getAuthUserId(): int
    {
        $id = $_SERVER['auth_user_id'] ?? null;

        if (!$id) {
            throw new AuthException('Unauthorized');
        }

        return (int) $id;
    }

    

    public function getByStage()
    {
  
            $userId =
            (int) ($_SERVER['auth_user_id'] ?? 0);
        $lectures = $this->lectureService->getLecturesByStage($userId);

        return ['success' => true, 'data' => $lectures, 'message' => 'Lectures reordered successfully'];
    } 
      public function getByStageForAdmin(int $id)
    {
   
        $lectures = $this->lectureService->getLecturesByStageForAdmin($id);

        return ['success' => true, 'data' => $lectures, 'message' => 'Lectures reordered successfully'];
    } 
} 
  