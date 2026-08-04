<?php

namespace App\Services;

use App\Contracts\UserRepositoryInterface;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthException;

class AuthService
{
    private UserRepositoryInterface $userRepo;
    private Logger $logger;

    public function __construct(
        UserRepositoryInterface $userRepo,
        Logger $logger
    ) {
        $this->userRepo = $userRepo;
        $this->logger = $logger;
    }

    public function login(string $email, 
    string $password): array
    {
        $user = $this->userRepo->findByEmail($email);

        if (!$user) {
            throw new ValidationException(['email' => 'بيانات تسجيل الدخول غير صحيحة.']);
        } 

        if (!$this->verifyPassword($password, $user['password_hash'])) {
            throw new ValidationException(['password' => 'بيانات تسجيل الدخول غير صحيحة.']);
        }

        if (!$this->isActive($user)) {
            throw new AuthException('تم تعطيل حسابك. يُرجى التواصل مع الإدارة للحصول على المساعدة.',);
        }

        $token = $this->generateToken($user);

        unset($user['password_hash']);

        $this->logger->info("User logged in", ['id' => $user['id'], 'role_id' => $user['role_id']]);

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
                    'stage' => [
                        'id' => $user['stage_id'],
                        'name' => $user['stage_name']
                    ]
                ]
                : null,

            'token' => $token
        ];
    }

public function generateToken(array $user): string
{
    return JWT::encode([
        'user_id'    => $user['id'],
        'student_id' => $user['student_id'] ?? null,
        'role_id'    => $user['role_id'],
        'email'      => $user['email'],
    ]);
}
    public function verifyPassword(string $plainPassword, string $hashedPassword): bool
    {
        return password_verify($plainPassword, $hashedPassword);
    }

    public function decodeToken(string $token): array
    {
        try {
            return JWT::decode($token);
        } catch (\Exception $e) {
            throw new AuthException('المستخدم غير موجود أو تم حذف الحساب');
        }
    }

    public function getAuthenticatedUser(string $token): array
    {
        $payload = $this->decodeToken($token);

        $user = $this->userRepo->find((int) $payload['user_id']);

        if (!$user) {
            throw new AuthException('User no longer exists');
        }

        if (!$this->isActive($user)) {
            throw new AuthException('Account is deactivated',);
        }

        unset($user['password']);

        return $user;
    }

    public function logout(): array
    {
        // JWT بطبيعته stateless. لو محتاج تبطل التوكن فعلياً محتاج blacklist في DB/Redis
        // ده placeholder بيرجع رد منطقي للـ client عشان يمسح التوكن بتاعه
        return ['message' => 'تم تسجيل الخروج بنجاح.'];
    }

    private function isActive(array $user): bool
    {
        return (bool) ($user['is_active'] ?? true);
    }
}
