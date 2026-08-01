<?php

namespace App\Services;

use App\Contracts\StudentRepositoryInterface;
use App\Contracts\StudentResultRepositoryInterface;
use App\Helpers\ImageUploadHelper;
use App\Core\Logger;
use App\Helpers\Pagination;
use App\Database\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class StudentResultService
{
    private StudentResultRepositoryInterface $resultRepo;
    private StudentRepositoryInterface $studentRepo;
    private Database $db;
    private ImageUploadHelper $imageUploader;
    private Logger $logger;

    public function __construct(
        StudentResultRepositoryInterface $resultRepo,
        StudentRepositoryInterface $studentRepo,
        Database $db,
        ImageUploadHelper $imageUploader,
        Logger $logger
    ) {
        $this->resultRepo = $resultRepo;
        $this->studentRepo = $studentRepo;
        $this->db = $db;
        $this->imageUploader = $imageUploader;
        $this->logger = $logger;
    }

    /**
     * @param array $requestData validated/sanitized fields (headline, description,
     *                            student_message, student_id, academic_year_id, stage_id?)
     * @param array|null $imageFile raw $_FILES['image'] entry, or null if none uploaded
     */
    public function createResult(array $requestData, ?array $imageFile, int $createdBy): array
    {
        $this->assertAcademicYearExists((int) $requestData['academic_year_id']);

        // Business rule: stage_id defaults to the student's CURRENT stage
        // (snapshot at time of publishing), but a teacher/admin may
        // explicitly override it for corrections — as long as it exists.
        $stageId =  $requestData['stage_id'];

        $this->assertStageExists($stageId);

        // Business rule: one result per student per academic year.
        // if ($this->resultRepo->existsForStudentInYear((int) $requestData['seat_number'], (int) $requestData['academic_year_id'])) {
        //     throw new ValidationException([
        //         'academic_year_id' => 'A result already exists for this student in this academic year. Use update instead.',
        //     ]);
        // }

        if ($imageFile !== null) {
            $this->imageUploader->validate($imageFile);
        }

        $imageUrl = null;

        $this->db->beginTransaction();

        try {
            // Upload only after all validation has passed and the transaction
            // has started, to minimize orphaned files on failure.
            if ($imageFile !== null && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $imageUrl = $this->imageUploader->store($imageFile);
            }

            $id = $this->resultRepo->create([
                'academic_year_id' => $requestData['academic_year_id'],
                'stage_id'         => $stageId,
                'seat_number'      => $requestData['seat_number'] ?? null,
                'student_name'     => $requestData['student_name'],
                'headline'         => $requestData['headline'],
                'description'      => $requestData['description'] ?? null,
                'student_message'  => $requestData['student_message'] ?? null,

                'total_score'      => $requestData['total_score'] ?? null,
                'percentage'       => $requestData['percentage'] ?? null,
                'grade'            => $requestData['grade'] ?? null,
                'rank'             => $requestData['rank'] ?? null,
                'is_published'     => $requestData['is_published'] ?? 1,

                'image_url'        => $imageUrl,
                'created_by'       => $createdBy,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            // Clean up the physical file since the DB row never committed.
            if ($imageUrl !== null) {
                $this->imageUploader->delete($imageUrl);
            }

            $this->logger->error('Student result creation failed', ['error' => $e->getMessage()]);

            // Concurrency edge case: two simultaneous requests both pass the
            // existsForStudentInYear() check, then race on the DB unique key.
            if ($this->isDuplicateKeyError($e)) {
                throw new ValidationException([
                    'academic_year_id' => 'A result already exists for this student in this academic year.',
                ]);
            }
 
            throw $e;
        }

        // $this->logger->info('Student result created', ['id' => $id, 'student_id' => $requestData['student_id']]);

        return $this->getResult($id);
    }

    public function getResult(int $id): array
    {
        $result = $this->resultRepo->find($id);

        if (!$result) {
            throw new NotFoundException('Student result');
        }

        return $result;
    }

    public function listResults(array $filters, array $queryParams): array
    {
        [$page, $perPage, $offset] = Pagination::resolve($queryParams);

        $sortBy = $queryParams['sort_by'] ?? 'created_at';
        $sortDir = $queryParams['sort_dir'] ?? 'desc';

        $items = $this->resultRepo->paginate($filters, $perPage, $offset, $sortBy, $sortDir);
        $total = $this->resultRepo->count($filters);

        return [
            'items' => $items,
            'pagination' => Pagination::meta($total, $page, $perPage),
        ];
    }

    public function updateResult(int $id, array $requestData, ?array $imageFile, int $updatedBy): array
    {
        $existing = $this->resultRepo->findRaw($id);

        if (!$existing) {
            throw new NotFoundException('Student result');
        }

        $academicYearId = (int) ($requestData['academic_year_id'] ?? $existing['academic_year_id']);


        if (isset($requestData['academic_year_id'])) {
            $this->assertAcademicYearExists($academicYearId);
        }

        if (isset($requestData['stage_id'])) {
            $this->assertStageExists((int) $requestData['stage_id']);
        }

        if ($imageFile !== null) {
            $this->imageUploader->validate($imageFile);
        }

        $updateData = [];
        $updateData = [];

        foreach (
            [
                'student_name',
                'seat_number',
                'academic_year_id',
                'stage_id',

                'headline',
                'description',
                'student_message',

                'total_score',
                'percentage',
                'grade',
                'rank',
                'is_published',
            ] as $field
        ) {
            if (array_key_exists($field, $requestData)) {
                $updateData[$field] = $requestData[$field];
            }
        }

        $newImageUrl = null;
        $oldImageUrl = $existing['image_url'];
        $hasNewImage = $imageFile !== null && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        $this->db->beginTransaction();

        try {
            if ($hasNewImage) {
                $newImageUrl = $this->imageUploader->store($imageFile);
                $updateData['image_url'] = $newImageUrl;
            }

            $updateData['updated_by'] = $updatedBy;

            if (!empty($updateData)) {
                $this->resultRepo->update($id, $updateData);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            if ($newImageUrl !== null) {
                $this->imageUploader->delete($newImageUrl);
            }

            $this->logger->error('Student result update failed', ['id' => $id, 'error' => $e->getMessage()]);

            if ($this->isDuplicateKeyError($e)) {
                throw new ValidationException([
                    'academic_year_id' => 'A result already exists for this student in this academic year.',
                ]);
            }

            throw $e;
        }

        // Old image is only removed after the DB commit succeeds, so a failed
        // request never leaves the record pointing at a deleted file.
        if ($hasNewImage && $oldImageUrl) {
            $this->imageUploader->delete($oldImageUrl);
        }

        $this->logger->info('Student result updated', ['id' => $id]);

        return $this->getResult($id);
    }

    public function deleteResult(int $id): void
    {
        $existing = $this->resultRepo->findRaw($id);

        if (!$existing) {
            throw new NotFoundException('Student result');
        }

        // Soft delete: this is a marketing/achievement record, not something
        // to permanently destroy. image_url is intentionally left in place
        // in case of accidental deletion / restore workflows.
        $this->resultRepo->softDelete($id);

        $this->logger->info('Student result soft-deleted', ['id' => $id]);
    }

    // ------------------------------------------------------------------
    // Business-rule guards
    // ------------------------------------------------------------------

    private function assertStudentExists(int $studentId): array
    {
        $student = $this->studentRepo->find($studentId);

        if (!$student) {
            throw new ValidationException(['student_id' => 'Student not found']);
        }

        if (!empty($student['deleted_at'])) {
            throw new ValidationException(['student_id' => 'Student account has been deleted']);
        }

        return $student;
    }

    private function assertAcademicYearExists(int $academicYearId): void
    {
        $year = $this->db->selectOne('SELECT id FROM academic_years WHERE id = ?', [$academicYearId]);

        if (!$year) {
            throw new ValidationException(['academic_year_id' => 'Academic year not found']);
        }
    }

    private function assertStageExists(int $stageId): void
    {
        $stage = $this->db->selectOne(
            'SELECT id FROM stages WHERE id = ? AND deleted_at IS NULL',
            [$stageId]
        );

        if (!$stage) {
            throw new ValidationException(['stage_id' => 'Stage not found']);
        }
    }

    private function isDuplicateKeyError(\Throwable $e): bool
    {
        return $e instanceof \PDOException && (int) $e->getCode() === 23000;
    }
}
