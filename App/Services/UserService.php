<?php

namespace App\Services;

use App\Contracts\UserRepositoryInterface;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;

class UserService
{
    private const ALLOWED_ROLES = ['1', '2', '3'];

    private UserRepositoryInterface $userRepo;
    private Logger $logger;

    public function __construct(
        UserRepositoryInterface $userRepo,
        Logger $logger
    ) {
        $this->userRepo = $userRepo;
        $this->logger = $logger;
    }

    public function createUser(array $data): array
    {
        if ($this->userRepo->exists($data['email'])) {
// Line 27
throw new ValidationException([
    'email' => 'البريد الإلكتروني مستخدم بالفعل.'
]);         }

        $role = $data['role_id'] ?? 'student';
        $this->assertValidRole($role);

        $userData = [
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'password_hash' =>
            $this->hashPassword($data['password_hash']),
            'role_id' => $role,
            'is_active' => $data['is_active'] ?? 1,
        ];

        $id = $this->userRepo->create($userData);

        $this->logger->info("New user created", ['id' => $id, 'email' => $data['email'], 'role' => $role]);

        return $this->getUser($id);
    }

    public function getUser(int $id): array
    {
        $user = $this->userRepo->find($id);

        if (!$user) {
throw new NotFoundException("المستخدم غير موجود.");        }

        return    [
            'user' => [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'is_active' => $user['is_active'],
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at'],

                'role' => [
                    'id' => $user['role_id'],
                    'code' => $user['role_name']
                ]
            ],

            'student' => $user['student_id']
                ? [
                    'id' => $user['student_id'],
                                        'qr_code' => $user['qr_code'],

                    'parent_phone' => $user['parent_phone'],
                    'profile_image' => $user['profile_image'],
                    'enrollment_date' => $user['enrollment_date'],
                    'average_score' => (float) $user['average_score'],
                    'total_quizzes' => (int) $user['total_quizzes'],
                    'total_degrees' => (int) $user['total_degrees'],
                    'total_lectures' => (int) $user['total_lectures'],
                    'created_at' => $user['student_created_at'],
                    'updated_at' => $user['student_updated_at'],

                    'stage' => [
                        'id' => $user['stage_id'],
                        'name' => $user['stage_name'],
                    ],
                ]

                : null, 

        ];
    }

    public function getUserRaw(int $id): array
    {
        // نسخة داخلية بترجع الـ password hash (تستخدم جوه الـ Services بس، ميترجعش للـ Controller)
        $user = $this->userRepo->find($id);

        if (!$user) {
            throw new NotFoundException("User");
        }

        return $user;
    }

    public function listUsers(int $limit = 50, int $offset = 0): array
    {
        return $this->userRepo->all($limit, $offset);
    }

    public function updateUser(int $id, array $data): array
    {
        $user = $this->getUserRaw($id);

        if (isset($data['email']) && $data['email'] !== $user['email']) {
            if ($this->userRepo->exists($data['email'])) {
                throw new ValidationException(['email' => 'Email already taken']);
            }
        }

        $allowedFields = array_intersect_key($data, array_flip(['name', 'email']));

        if (!empty($allowedFields)) {
            $this->userRepo->update($id, $allowedFields);
        }

        $this->logger->info("User updated", ['id' => $id]);

        return $this->getUser($id);
    }

    public function deleteUser(int $id): void
    {
        $this->getUserRaw($id); // يتأكد إنه موجود
        $this->userRepo->delete($id);
        $this->logger->info("User deleted", ['id' => $id]);
    }

    public function changePassword(int $id, string $currentPassword, string $newPassword): void
    {
        $user = $this->getUserRaw($id);

        if (!password_verify($currentPassword, $user['password'])) {
// Line 143
throw new ValidationException([
    'current_password' => 'كلمة المرور الحالية غير صحيحة.'
]);        }

        $this->userRepo->updatePassword($id, $this->hashPassword($newPassword));
        $this->logger->info("Password changed", ['id' => $id]);
    }

    public function activateUser(int $id): array
    {
        $this->getUserRaw($id);
        $this->userRepo->setActive($id, true);
        $this->logger->info("User activated", ['id' => $id]);

        return $this->getUser($id);
    }

    public function deactivateUser(int $id): array
    {
        $this->getUserRaw($id);
        $this->userRepo->setActive($id, false);
        $this->logger->info("User deactivated", ['id' => $id]);

        return $this->getUser($id);
    }

    public function changeRole(int $id, string $newRole): array
    {
        $this->assertValidRole($newRole);
        $this->getUserRaw($id);

        $this->userRepo->updateRole($id, $newRole);
        $this->logger->info("User role changed", ['id' => $id, 'role' => $newRole]);

        return $this->getUser($id);
    }

    public function hashPassword(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    public function checkDuplicateEmail(string $email): void
    {
        if ($this->userRepo->exists($email)) {
// Line 187
throw new ValidationException([
    'email' => 'البريد الإلكتروني مستخدم بالفعل.'
]);        }
    }
 
    private function assertValidRole(string $role): void
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new ValidationException(['role' => 'Invalid role: ' . $role]);
        }
    }

    private function hideSensitive(array $user): array
    {
        unset($user['password']);
        return $user;
    }
}
