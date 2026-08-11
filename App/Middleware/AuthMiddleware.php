<?php

namespace App\Middleware;

use App\Services\JWT;
use App\Exceptions\AuthException;

class AuthMiddleware
{
    public function handle(): void
    {
        $token = $this->extractToken();

        if (!$token) {
            throw new AuthException("Authorization token is missing", []);
        }

        try {
            $payload = JWT::decode($token);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
            exit;
        }

        if (!isset($payload['user_id']) || !isset($payload['role_id'])) {
            throw new AuthException("Malformed token payload");
        }

        // بتتخزن عشان الـ Middlewares والـ Controllers اللي جاية تقدر تستخدمها
        $_SERVER['auth_user_id'] = $payload['user_id'];
if (isset($payload['student_id'])) { $_SERVER['auth_student_id'] = (int) $payload['student_id']; }
        $_SERVER['auth_role'] = $payload['role_id'];
        $_SERVER['auth_email'] = $payload['email'] ?? null;
    }

    private function extractToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        $authHeader = $headers['Authorization']
            ?? $headers['authorization']
            ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return substr($authHeader, 7);
    }
}
 