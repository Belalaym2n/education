<?php

namespace App\Services;

use App\Contracts\StageRepositoryInterface;
use App\Core\Logger;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * StageService
 *
 * ALL business rules for the Stage module live here. Controllers only
 * validate shape/format and call into this class; the Repository only
 * touches the database. Rules enforced:
 *  - name required, trimmed, unique among non-deleted stages
 *  - order_number unique among non-deleted stages; auto-generated if omitted
 *  - soft-deleted stages are invisible to normal find()/list()/search()
 *  - deleting a stage with active students assigned is blocked (409)
 *  - restoring a stage that isn't deleted is blocked (409)
 */
class StageService
{
    private StageRepositoryInterface $stageRepo;
    private Logger $logger;

    public function __construct(StageRepositoryInterface $stageRepo, Logger $logger)
    {
        $this->stageRepo = $stageRepo;
        $this->logger = $logger;
    }

    public function createStage(array $data): array
    {
        $name = trim((string) $data['name']);

        if ($name === '') {
            throw new ValidationException(['name' => 'Stage name is required']);
        }

        if ($this->stageRepo->existsByName($name)) {
            throw new ValidationException(['name' => 'Stage name already exists']);
        }

        $orderNumber = (isset($data['order_number']) && $data['order_number'] !== '')
            ? (int) $data['order_number']
            : $this->stageRepo->nextOrderNumber();

        if ($orderNumber < 1) {
            throw new ValidationException(['order_number' => 'order_number must be a positive integer']);
        }

        if ($this->stageRepo->existsByOrder($orderNumber)) {
            throw new ValidationException(['order_number' => 'Order number already in use']);
        }

        $id = $this->stageRepo->create([
            'name'         => $name,
            'order_number' => $orderNumber,
        ]);

        $this->logger->info('Stage created', ['id' => $id, 'name' => $name]);

        return $this->getStage($id);
    }

    public function getStage(int $id): array
    {
        $stage = $this->stageRepo->find($id);

        if (!$stage) {
            throw new NotFoundException('Stage');
        }

        return $stage;
    }

    /** Admin-only lookup that can also return soft-deleted stages. */
    public function getStageWithTrashed(int $id): array
    {
        $stage = $this->stageRepo->findById($id, true);

        if (!$stage) {
            throw new NotFoundException('Stage');
        }

        return $stage;
    }


    public function listStages(bool $includeTrashed = false): array
    {
        return $this->stageRepo->all($includeTrashed);
    }

    public function getYearsAcademy(bool $includeTrashed = false): array
    {
        return $this->stageRepo->getYears($includeTrashed);
    }
    public function paginateStages(int $page, int $perPage, array $filters = []): array
    {
        return $this->stageRepo->paginate($page, $perPage, $filters);
    }
 
    public function searchStages(string $keyword, bool $includeTrashed = false): array
    {
        return $this->stageRepo->search($keyword, $includeTrashed);
    }

    public function updateStage(int $id, array $data): array
    {
        $stage = $this->getStage($id);
        $updates = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '') {
                throw new ValidationException(['name' => 'Stage name is required']);
            }

            if ($name !== $stage['name'] && $this->stageRepo->existsByName($name, $id)) {
                throw new ValidationException(['name' => 'Stage name already exists']);
            }

            $updates['name'] = $name;
        }

        if (array_key_exists('order_number', $data) && $data['order_number'] !== '') {
            $orderNumber = (int) $data['order_number'];

            if ($orderNumber < 1) {
                throw new ValidationException(['order_number' => 'order_number must be a positive integer']);
            }

            if ($orderNumber !== (int) $stage['order_number'] && $this->stageRepo->existsByOrder($orderNumber, $id)) {
                throw new ValidationException(['order_number' => 'Order number already in use']);
            }

            $updates['order_number'] = $orderNumber;
        }

        if (!empty($updates)) {
            $this->stageRepo->update($id, $updates);
            $this->logger->info('Stage updated', ['id' => $id, 'fields' => array_keys($updates)]);
        }

        return $this->getStage($id);
    }

    public function deleteStage(int $id): void
    {
        $this->getStage($id); // throws NotFoundException if missing/already deleted

        $studentsCount = $this->stageRepo->studentsCount($id);

        if ($studentsCount > 0) {
            throw new ConflictException(
                'Cannot delete stage: active students are assigned to it',
                ['students_count' => $studentsCount]
            );
        }

        $this->stageRepo->delete($id);
        $this->logger->info('Stage soft-deleted', ['id' => $id]);
    }

    /** Admin-only: bring back a soft-deleted stage. */
    public function restoreStage(int $id): array
    {
        $stage = $this->stageRepo->findById($id, true);

        if (!$stage) {
            throw new NotFoundException('Stage');
        }

        if ($stage['deleted_at'] === null) {
            throw new ConflictException('Stage is not deleted');
        }

        $this->stageRepo->restore($id);
        $this->logger->info('Stage restored', ['id' => $id]);

        return $this->getStage($id);
    }
}
