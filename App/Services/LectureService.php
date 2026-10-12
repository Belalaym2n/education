<?php

namespace App\Services;

use App\Contracts\LectureRepositoryInterface;
use App\Contracts\StudentRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class LectureService
{
    private LectureRepositoryInterface $lectureRepo;
    private StudentRepositoryInterface $studentRepo;
    private Database $db;
    private Logger $logger;

    public function __construct(
        LectureRepositoryInterface $lectureRepo,
        StudentRepositoryInterface $studentRepo,
        Database $db,
        Logger $logger
    ) {
        $this->lectureRepo = $lectureRepo;
        $this->studentRepo = $studentRepo;
        $this->db = $db;
        $this->logger = $logger;
    }

    // =========================================================
    // ADMIN
    // =========================================================

    public function createLecture(array $data, int $createdBy): array
    {
        $this->assertStageExists((int) $data['stage_id']);

        $slug = $this->generateUniqueSlug($data['title']);

        $orderNumber = isset($data['order_number']) && (int) $data['order_number'] > 0
            ? (int) $data['order_number']
            : $this->lectureRepo->getNextOrderNumber((int) $data['stage_id']);

        if ($this->lectureRepo->orderNumberExistsInStage((int) $data['stage_id'], $orderNumber)) {
            throw new ValidationException([
                'order_number' => 'Order number already used within this stage',
            ]);
        }

        $payload = [
            'stage_id'         => (int) $data['stage_id'],
            'title'            => $data['title'],
            'slug'             => $slug,
            'description'      => $data['description'] ?? null,
            'youtube_url'      => $data['youtube_url'],
            'thumbnail_url'    => $data['thumbnail_url'] ?? null,
            'duration_seconds' => isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            'order_number'     => $orderNumber,
            'price'            => (float) ($data['price'] ?? 0.00),
            'is_free'          => !empty($data['is_free']) ? 1 : 0,
            'is_published'     => 1,
            'published_at'     => null,
            'created_by'       => $createdBy,
             'max_views'    => $data['max_views'] ?? 2,

        ];

        $id = $this->lectureRepo->create($payload);

        $this->logger->info('Lecture created', ['id' => $id, 'stage_id' => $payload['stage_id'], 'created_by' => $createdBy]);

        return $this->getLectureForAdmin($id);
    }

    public function updateLecture(int $id, array $data): array
    {
        $lecture = $this->lectureRepo->find($id);
        if (!$lecture) {
            throw new NotFoundException('Lecture');
        }

        $targetStageId = isset($data['stage_id']) ? (int) $data['stage_id'] : (int) $lecture['stage_id'];

        if (isset($data['stage_id'])) {
            $this->assertStageExists($targetStageId);
        }

        if (isset($data['order_number'])) {
            $newOrder = (int) $data['order_number'];
            if ($this->lectureRepo->orderNumberExistsInStage($targetStageId, $newOrder, $id)) {
                throw new ValidationException([
                    'order_number' => 'Order number already used within this stage',
                ]);
            }
        }

        $allowedFields = [
            'stage_id',
            'title',
            'description',
            'youtube_url',
            'thumbnail_url',
            'duration_seconds',
                'max_views',
            'order_number',
            'price',
            'is_free',
        ];

        $payload = array_intersect_key($data, array_flip($allowedFields));

        if (empty($payload)) {
            return $this->getLectureForAdmin($id);
        }

        $this->lectureRepo->update($id, $payload);

        $this->logger->info('Lecture updated', ['id' => $id, 'fields' => array_keys($payload)]);

        return $this->getLectureForAdmin($id);
    }

    public function deleteLecture(int $id): void
    {
        $lecture = $this->lectureRepo->find($id);
        if (!$lecture) {
            throw new NotFoundException('Lecture');
        }

        $this->lectureRepo->softDelete($id);

        $this->logger->info('Lecture deleted', ['id' => $id, 'stage_id' => $lecture['stage_id']]);
    }

    public function publishLecture(int $id): array
    {
        $lecture = $this->lectureRepo->find($id);
        if (!$lecture) {
            throw new NotFoundException('Lecture');
        }

        if ((int) $lecture['is_published'] === 1) {
            return $this->getLectureForAdmin($id); // idempotent
        }

        $this->lectureRepo->update($id, [
            'is_published' => 1,
            'published_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('Lecture published', ['id' => $id]);

        return $this->getLectureForAdmin($id);
    }

    public function unpublishLecture(int $id): array
    {
        $lecture = $this->lectureRepo->find($id);
        if (!$lecture) {
            throw new NotFoundException('Lecture');
        }

        if ((int) $lecture['is_published'] === 0) {
            return $this->getLectureForAdmin($id); // idempotent
        }

        $this->lectureRepo->update($id, ['is_published' => 0]);

        $this->logger->info('Lecture unpublished', ['id' => $id]);

        return $this->getLectureForAdmin($id);
    }

    /**
     * @param array<int, array{id:int, order_number:int}> $items
     */
    public function reorderLectures(int $stageId, array $items): array
    {
        $this->assertStageExists($stageId);

        if (empty($items)) {
            throw new ValidationException(['items' => 'At least one lecture must be provided for reordering']);
        }

        $orderNumbers = array_map(fn($item) => (int) $item['order_number'], $items);
        if (count($orderNumbers) !== count(array_unique($orderNumbers))) {
            throw new ValidationException(['order_number' => 'Duplicate order numbers in the submitted list']);
        }

        $this->db->beginTransaction();

        try {
            foreach ($items as $item) {
                $lectureId = (int) $item['id'];
                $lecture = $this->lectureRepo->find($lectureId);

                if (!$lecture) {
                    throw new NotFoundException('Lecture');
                }

                if ((int) $lecture['stage_id'] !== $stageId) {
                    throw new ValidationException([
                        'id' => "Lecture $lectureId does not belong to stage $stageId",
                    ]);
                }

                $this->lectureRepo->update($lectureId, ['order_number' => (int) $item['order_number']]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Lecture reorder failed', ['stage_id' => $stageId, 'error' => $e->getMessage()]);
            throw $e;
        }

        $this->logger->info('Lectures reordered', ['stage_id' => $stageId, 'count' => count($items)]);

        return $this->lectureRepo->allForAdmin(['stage_id' => $stageId], 200, 0, 'order_number', 'asc');
    }

    public function getLectureForAdmin(int $id): array
    {
        $lecture = $this->lectureRepo->find($id);
        if (!$lecture) {
            throw new NotFoundException('Lecture');
        }

        return $lecture;
    } 

    public function listForAdmin(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        return $this->lectureRepo->allForAdmin($filters, $limit, $offset, $sort, $order);
    }

    // =========================================================
    // STUDENT
    // =========================================================

    public function listForStudent(int $authUserId, array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        $stageId = $this->resolveStudentStageId($authUserId);

        return $this->lectureRepo->allForStudent($stageId, $filters, $limit, $offset, $sort, $order);
    }

    public function getLectureForStudent(int $authUserId, int $lectureId): array
    {
        $stageId = $this->resolveStudentStageId($authUserId);

        $lecture = $this->lectureRepo->find($lectureId);

        // Unpublished or soft-deleted lectures are reported as "not found" to avoid
        // leaking existence of content the student is not entitled to see.
        if (!$lecture || (int) $lecture['is_published'] !== 1) {
            throw new NotFoundException('Lecture');
        }

        if ((int) $lecture['stage_id'] !== $stageId) {
            throw new ForbiddenException();
        }

        unset($lecture['created_by']);

        return $lecture;
    }

    // =========================================================
    // INTERNAL HELPERS
    // =========================================================

    private function assertStageExists(int $stageId): void
    {
        if (!$this->lectureRepo->stageExists($stageId)) {
            throw new NotFoundException('Stage');
        }
    }

    private function resolveStudentStageId(int $authUserId): int
    {
        $student = $this->studentRepo->find($authUserId);

        if (!$student || empty($student['stage_id'])) {
            throw new NotFoundException('Student');
        }

        return (int) $student['stage_id'];
    }

    private function generateUniqueSlug(string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-');

        if ($base === '') {
            $base = 'lecture';
        }

        $slug = $base;
        $suffix = 1;

        while ($this->lectureRepo->slugExists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Student view of their own stage's lectures. $authUserId is the
     * authenticated user's id (the parameter was previously misnamed).
     *
     * Security: returns PUBLISHED lectures only. It used to call
     * findByStageId(), which also returned unpublished lectures (including
     * their youtube_url) to students.
     */
    public function getLecturesByStage(int $authUserId): array
    {
        $stageId = $this->resolveStudentStageId($authUserId);
        $this->assertStageExists($stageId);

        return $this->lectureRepo->findPublishedByStageId($stageId);
    }

    public function getLecturesByStageForAdmin(int $stageId): array
{


 
 
  
    return $this->lectureRepo->findByStageId($stageId);
}
}  
 