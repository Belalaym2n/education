<?php

namespace App\Services;

use App\Contracts\StudentRepositoryInterface;
use App\Contracts\UserRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;

class StudentService
{
    private StudentRepositoryInterface $studentRepo;
    private UserRepositoryInterface $userRepo;
    private UserService $userService;
    private AuthService $authService;
    private Database $db;
    private Logger $logger;

    public function __construct(
        StudentRepositoryInterface $studentRepo,
        UserRepositoryInterface $userRepo,
        UserService $userService,
        AuthService $authService,
        Database $db,
        Logger $logger
    ) {
        $this->studentRepo = $studentRepo;
        $this->userRepo = $userRepo;
        $this->userService = $userService;
        $this->authService = $authService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * تسجيل طالب جديد: بيعمل User + Student profile جوه Transaction واحدة
     */
    public function registerStudent(array $data): array
    {
        $this->userService->checkDuplicateEmail($data['email']);

        $this->db->beginTransaction();

        try {
             $userId = $this->userRepo->create([
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone'
                => $data['phone'],

                'password_hash' =>
                $this->userService->hashPassword($data['password']),
                'role_id' => $data['role_id'],
                'is_active' => 1
            ]);

            // 2. Create Student profile مرتبط بالـ user_id
            $studentId = $this->studentRepo->create([
                'user_id' => $userId,
                'stage_id' => $data['stage_id'],
                'parent_phone' => $data['parent_phone'],
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error("Student registration failed", ['error' => $e->getMessage()]);
            throw $e;
        }

        $user = $this->userService->getUser($userId);
        $token = $this->authService->generateToken(
            array_merge($user, ['id' => $userId])
        );

        $this->logger->info("New student registered", ['user_id' => $userId, 'student_id' => $studentId]);

        return [
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $user['role_name'], // student
                'is_active' => (bool) $user['is_active'],
            ],
            'student' => [ 
  
                'id' => (int) $studentId,
                'stage' => [
                    'id' => (int) $user['stage_id'],
                    'name' => $user['stage_name'],
                ],
            ],
            'token' => $token,
        ];
    }

    public function getStudent(int $id): array
    {
        $student = $this->studentRepo->find($id);

        if (!$student) {
            throw new NotFoundException("Student");
        }

        return $student;
    }

    public function listStudents(int $limit = 50, int $offset = 0): array
    {
        return $this->studentRepo->all($limit, $offset);
    }

    public function updateStudent(int $id, array $data): array
    {
        $this->getStudent($id); // يرمي NotFoundException لو مش موجود

        $allowedFields = array_intersect_key($data, array_flip(['stage_id', 'phone', 'parent_phone']));

        if (!empty($allowedFields)) {
            $this->studentRepo->update($id, $allowedFields);
        }

        $this->logger->info("Student updated", ['id' => $id]);

        return $this->getStudent($id);
    }

    public function deleteStudent(int $id): void
    {
        $student = $this->getStudent($id); // يتأكد إنه موجود

        $this->db->beginTransaction();

        try {
            $this->studentRepo->delete($id);
            // اختياري: امسح الـ user المرتبط كمان لو ده المطلوب
            // $this->userRepo->delete($student['user_id']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info("Student deleted", ['id' => $id]);
    }
}
