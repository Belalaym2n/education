<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\StudentResultService;
use App\Validators\Validator;

class StudentResultController
{
    private StudentResultService $service;

    public function __construct(StudentResultService $service)
    {
        $this->service = $service;
    }

    public function index(): array
    {
        $query = $_GET;

        $filters = [
            'academic_year_id' => $query['academic_year_id'] ?? null,
            'student_id' => $query['student_id'] ?? null,
            'stage_id' => $query['stage_id'] ?? null,
            'teacher_id' => $query['teacher_id'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'updated_from' => $query['updated_from'] ?? null,
            'updated_to' => $query['updated_to'] ?? null,
            'keyword' => isset($query['search']) ? trim($query['search']) : null,
        ];

        $result = $this->service->listResults($filters, $query);

        return [
            'success' => true,
            'data' => $result['items'],
            'pagination' => $result['pagination'],
        ];
    }

    public function show(int $id): array
    {
        $result = $this->service->getResult($id);
        return ['success' => true, 'data' => $result];
    }

    public function store(array $requestData): array
    {
        $input = $this->resolveInput($requestData);
        $clean = $this->validate($input, true);

        $authUserId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $imageFile = $_FILES['image'] ?? null;

        $result = $this->service->createResult($clean, $imageFile, $authUserId);

        return ['success' => true, 'message' => 'Student result created', 'data' => $result];
    }

    public function update(int $id, array $requestData): array
    {
        $input = $this->resolveInput($requestData);
        $clean = $this->validate($input, false);

        $authUserId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $imageFile = $_FILES['image'] ?? null;

        $result = $this->service->updateResult($id, $clean, $imageFile, $authUserId);

        return ['success' => true, 'message' => 'Student result updated', 'data' => $result];
    }

    public function destroy(int $id): array
    {
        $this->service->deleteResult($id);
        return ['success' => true, 'message' => 'Student result deleted'];
    }

    /**
     * The Router always JSON-decodes php://input for POST/PUT/PATCH, which
     * yields [] for multipart/form-data requests (image upload). This falls
     * back to $_POST (populated correctly by PHP for multipart requests)
     * whenever the JSON body came back empty.
     */
    private function resolveInput(array $requestData): array
    {
        if (!empty($requestData)) {
            return $requestData;
        }

        return $_POST;
    }

    private function validate(array $data, bool $isCreate): array
    {
        $validator = new Validator($data);

        if ($isCreate) {
            $validator->required('stage_id');
            $validator->required('academic_year_id');
            $validator->required('student_message');
            $validator->required('seat_number');

            $validator->required('headline');
            $validator->required('percentage'); 
        }

        if (isset($data['stage_id']) || $isCreate) {
            $validator->validateNumber('stage_id', 1);
        }

        if (isset($data['academic_year_id']) || $isCreate) {
            $validator->validateNumber('academic_year_id', 1);
        }

        if (isset($data['seat_number']) || $isCreate) {
            $validator->validateNumber('seat_number', 1);
        }




        if (isset($data['headline']) || $isCreate) {
            $validator->validateString('headline', 3, 200);
        }

        if (isset($data['description']) && $data['description'] !== '') {
            $validator->max('description', 5000);
        }

        if (isset($data['student_message']) && $data['student_message'] !== '') {
            $validator->max('student_message', 2000);
        }

        if (isset($data['percentage']) || $isCreate) {
            $validator->validateNumber('percentage', 0, 100);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }



        $fields = array_intersect(
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
            ],
            array_keys($data)
        );

        return $validator->getSanitized($fields);
    }
}
