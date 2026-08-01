<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\StudentService;
use App\Validators\Validator;

class StudentController
{
    private StudentService $studentService;

    public function __construct(StudentService $studentService)
    {
        $this->studentService = $studentService;
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
        $this->studentService->deleteStudent($id);
        return ['success' => true, 'message' => 'Student deleted'];
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

    // register و login اتشالوا بالكامل من هنا
    // register -> AuthController::registerStudent
    // login    -> AuthController::login
}
