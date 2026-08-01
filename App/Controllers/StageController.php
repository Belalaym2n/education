<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\StageService;
use App\Validators\Validator;

/**
 * StageController
 *
 * Thin by design: validate the request shape, call StageService, return a
 * JSON-able array in the standard { success, message, data, errors } shape
 * used everywhere else in this project.
 */
class StageController
{
    private StageService $stageService;
     public function __construct(StageService $stageService)
    {
        $this->stageService = $stageService;
    }

    /** 
     * GET /api/stages
     * Supports: ?search=, ?page=&per_page=, ?sort_by=&sort_direction=,
     * ?name=, ?order_number=, and (admin only) ?with_trashed=1 / ?only_trashed=1
     */
    public function index(): array
    {
        // $isAdmin = $this->isAdmin();

        // // $search = $_GET['search'] ?? null;
        // $withTrashed = $isAdmin && ($_GET['with_trashed'] ?? '') === '1';
        // $onlyTrashed = $isAdmin && ($_GET['only_trashed'] ?? '') === '1';

        // if (!empty($search)) {
        //     $data = $this->stageService->searchStages((string) $search, $withTrashed || $onlyTrashed);

        //     return ['success' => true, 'message' => null, 'data' => $data, 'errors' => null];
        // }

        // if (isset($_GET['page'])) {
        //     $page = max(1, (int) $_GET['page']);
        //     $perPage = (int) ($_GET['per_page'] ?? 20);

        //     $filters = [
        //         'with_trashed'   => $withTrashed,
        //         'only_trashed'   => $onlyTrashed,
        //         'sort_by'        => $_GET['sort_by'] ?? 'order_number',
        //         'sort_direction' => $_GET['sort_direction'] ?? 'ASC',
        //     ];

        //     if (!empty($_GET['name'])) {
        //         $filters['name'] = $_GET['name'];
        //     }

        //     if (isset($_GET['order_number']) && $_GET['order_number'] !== '') {
        //         $filters['order_number'] = (int) $_GET['order_number'];
        //     }

        //     $result = $this->stageService->paginateStages($page, $perPage, $filters);

        //     return [
        //         'success' => true,
        //         'message' => null,
        //         'data'    => $result['items'],
        //         'meta'    => [
        //             'total'       => $result['total'],
        //             'page'        => $result['page'],
        //             'per_page'    => $result['per_page'],
        //             'total_pages' => $result['total_pages'],
        //         ],
        //         'errors'  => null,
        //     ];
        // }

        $data = $this->stageService->listStages();

        return [
            'success' => true,
            'message' => "Stages retrived success",
            'data' => $data,
        ];
    }

    /** GET /api/stages/{id} — admins can pass ?with_trashed=1 */
    public function show(int $id): array
    {
        $withTrashed = $this->isAdmin() && (($_GET['with_trashed'] ?? '') === '1');

        $stage = $withTrashed
            ? $this->stageService->getStageWithTrashed($id)
            : $this->stageService->getStage($id);

        return ['success' => true, 'message' => null, 'data' => $stage, 'errors' => null];
    }

    public function getYearsAcademy(): array
    {

        $years_academy =  $this->stageService->getYearsAcademy();

        return ['success' => true, 'message' => null, 'data' => $years_academy, 'errors' => null];
    }


    /** POST /api/stages */
    public function store(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('name');
        $validator->validateString('name', 2, 100);

        if (!$validator->hasErrors()) {
            $validator->pattern(
                'name',
                '/^[\p{L}\p{N}\s\-]+$/u',
                'name contains invalid characters'
            );
        }

        if (isset($requestData['order_number']) && $requestData['order_number'] !== '') {
            $validator->validateNumber('order_number', 1);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $validator->getSanitized(['name']);
        $clean['name'] = trim($clean['name']);

        if (isset($requestData['order_number']) && $requestData['order_number'] !== '') {
            $clean['order_number'] = (int) $requestData['order_number'];
        }

        $stage = $this->stageService->createStage($clean);

        return ['success' => true, 'message' => 'Stage created successfully', 'data' => $stage, 'errors' => null];
    }

    /** PUT/PATCH /api/stages/{id} — partial updates supported */
    public function update(int $id, array $requestData): array
    {
        $validator = new Validator($requestData);

        if (isset($requestData['name'])) {
            $validator->validateString('name', 2, 100);

            if (!$validator->hasErrors()) {
                $validator->pattern(
                    'name',
                    '/^[\p{L}\p{N}\s\-]+$/u',
                    'name contains invalid characters'
                );
            }
        }

        if (isset($requestData['order_number']) && $requestData['order_number'] !== '') {
            $validator->validateNumber('order_number', 1);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = [];

        if (isset($requestData['name'])) {
            $sanitized = $validator->getSanitized(['name']);
            $clean['name'] = trim($sanitized['name']);
        }

        if (isset($requestData['order_number']) && $requestData['order_number'] !== '') {
            $clean['order_number'] = (int) $requestData['order_number'];
        }

        $stage = $this->stageService->updateStage($id, $clean);

        return ['success' => true, 'message' => 'Stage updated successfully', 'data' => $stage, 'errors' => null];
    }

    /** DELETE /api/stages/{id} — soft delete */
    public function destroy(int $id): array
    {
        $this->stageService->deleteStage($id);

        return ['success' => true, 'message' => 'Stage deleted successfully', 'data' => null, 'errors' => null];
    }

    /** PATCH /api/stages/{id}/restore — admin only */
    public function restore(int $id): array
    {
        $stage = $this->stageService->restoreStage($id);

        return ['success' => true, 'message' => 'Stage restored successfully', 'data' => $stage, 'errors' => null];
    }

    private function isAdmin(): bool
    {
        return isset($_SERVER['auth_role']) && (string) $_SERVER['auth_role'] === '3';
    }
}
