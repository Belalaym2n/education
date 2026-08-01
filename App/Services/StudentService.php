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

        $user = $this->studentRepo->find($userId);

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

    public function listStudents(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        return $this->studentRepo->all($filters, $limit, $offset, $sort, $order);
    }

    public function updateStudent(int $id, array $data): array
    {
        $student = $this->getStudent($id);

        // ==========================
        // Update students table
        // ==========================
        $studentData = array_intersect_key(
            $data,
            array_flip([
                'stage_id',
                'parent_phone',
                'profile_image',
                'enrollment_date',
            ])
        );

        if (!empty($studentData)) {
            $affected = $this->studentRepo->update(
                (int) $student['student_id'],
                $studentData
            );

            $this->logger->info('Students table updated', [
                'student_id' => $student['student_id'],
                'affected_rows' => $affected,
                'data' => $studentData,
            ]);
        }

        // ==========================
        // Update users table
        // ==========================
        $userData = array_intersect_key(
            $data,
            array_flip([
                'full_name',
                'email',
                'phone',
                'is_active',
            ])
        );

        if (!empty($userData)) {
            $affected = $this->userRepo->update(
                $id,
                $userData
            );

            $this->logger->info('Users table updated', [
                'user_id' => $student['id'],
                'affected_rows' => $affected,
                'data' => $userData,
            ]);
        }

        // Reload updated student
        $updated = $this->getStudent($id);

        $this->logger->info('Final student after update', $updated);

        return $updated;
    }
    public function deleteStudent(int $id): void
    {
        $student = $this->getStudent($id); // يتأكد إنه موجود

        // var_dump($student);

        // die; 
if (!$student) {
    throw new NotFoundException('User not found');
} 
        $this->db->beginTransaction();

        try {
            $this->studentRepo->delete($student['student_id']);
             $this->userRepo->delete($id); 
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack(); 
            throw $e; 
        } 
 
        $this->logger->info("Student deleted", ['id' => $id]);
    }
}
   