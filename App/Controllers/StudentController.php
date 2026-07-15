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
        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = (int) ($_GET['offset'] ?? 0);

        $students = $this->studentService->listStudents($limit, $offset);

        return ['success' => true, 'data' => $students];
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
        if (isset($requestData['student_phone'])) {
            $validator->validatePhone('student_phone');
        }
        if (isset($requestData['father_phone'])) {
            $validator->validatePhone('father_phone');
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $fields = array_intersect(['stage_id', 'student_phone', 'father_phone'], array_keys($requestData));
        $clean = $validator->getSanitized($fields);

        // إعادة تسمية المفاتيح عشان تتوافق مع أعمدة students table
        $mapped = [];
        if (isset($clean['stage_id'])) $mapped['stage_id'] = $clean['stage_id'];
        if (isset($clean['student_phone'])) $mapped['phone'] = $clean['student_phone'];
        if (isset($clean['father_phone'])) $mapped['parent_phone'] = $clean['father_phone'];

        $student = $this->studentService->updateStudent($id, $mapped);

        return ['success' => true, 'data' => $student];
    }

    public function destroy(int $id): array
    {
        $this->studentService->deleteStudent($id);
        return ['success' => true, 'message' => 'Student deleted'];
    }

    // register و login اتشالوا بالكامل من هنا
    // register -> AuthController::registerStudent
    // login    -> AuthController::login
}