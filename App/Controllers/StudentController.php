<?php

namespace App\Controllers;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Services\StudentService;
use App\Validators\Validator;
use App\Services\DeviceBindingService;

class StudentController
{
    private StudentService $studentService;
    private DeviceBindingService $deviceBindingService;


    public function __construct(
        StudentService
        $studentService,
        DeviceBindingService $deviceBindingService

    ) {

        $this->studentService = $studentService;

        $this->deviceBindingService = $deviceBindingService;
    }

    public function index(): array
    {
        [$limit, $offset, $sort, $order] = $this->getPaginationParams();

        $search = isset($_GET['search'])
            ? trim((string)$_GET['search'])
            : null;

        $filters = [
            'search' => $search,
        ];

        $result = $this->studentService->listStudents(
            $filters,
            $limit,
            $offset,
            $sort,
            $order
        );

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



    public function show(int $id): array
    {
        $student = $this->studentService->getStudent($id);
        return ['success' => true, 'data' => $student];
    }
    public function update(int $id, array $requestData): array
    {
        // {id} is the user id. Non-admins may only edit their own record and
        // may never change their activation state.
        $this->assertSelfOrAdmin($id);
        if (!$this->isAdmin()) {
            unset($requestData['is_active']);
        }

        $validator = new Validator($requestData);

        if (isset($requestData['stage_id'])) {
            $validator->validateNumber('stage_id');
        }

        if (isset($requestData['phone'])) {
            $validator->validatePhone('phone');
        }

        if (isset($requestData['parent_phone'])) {
            $validator->validatePhone('parent_phone');
        }

        if (isset($requestData['email'])) {
            $validator->validateEmail('email');
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $fields = array_intersect(
            [
                'full_name',
                'phone',
                'parent_phone',
                'stage_id',
                'is_active',
            ],
            array_keys($requestData)
        );

        $clean = $validator->getSanitized($fields);

        $student = $this->studentService->updateStudent($id, $clean);

        return [
            'success' => true,
            'data' => $student,
        ];
    }

    public function destroy(int $id): array
    {
        $this->assertSelfOrAdmin($id);
        $this->studentService->deleteStudent($id);
        return ['success' => true, 'message' => 'Student deleted'];
    }

    private function isAdmin(): bool
    {
        return (int) ($_SERVER['auth_role'] ?? 0) === 3;
    }

    /** The authenticated user id comes from AuthMiddleware, never the request. */
    private function assertSelfOrAdmin(int $userId): void
    {
        if ($this->isAdmin()) {
            return;
        }
        if ((int) ($_SERVER['auth_user_id'] ?? 0) !== $userId) {
            throw new ForbiddenException();
        }
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

    public function resetDevice(int $id): array 
    {
        $this->deviceBindingService->
        resetDevice($id); // $id is students.id here, not user_id
        return ['success' => true, 'data' => ['message' => 'Device binding reset.']];
    }

    // register و login اتشالوا بالكامل من هنا
    // register -> AuthController::registerStudent
    // login    -> AuthController::login
}
  