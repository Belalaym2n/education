<?php

namespace App\Middleware;

use App\Services\JWT;
use App\Services\DeviceBindingService;
use App\Exceptions\AuthException;

class AuthMiddleware
{
    private const STUDENT_ROLE_ID = 1;
    private const DEVICE_HEADER = 'X-Device-ID';

    private DeviceBindingService $deviceBindingService;

    public function __construct(DeviceBindingService $deviceBindingService)
    {
        $this->deviceBindingService = $deviceBindingService;
    }

    public function handle(): void
    {
        $token = $this->extractToken();

        if (!$token) {
            throw new AuthException("Authorization token is missing", []);
        }

        try {
            $payload = JWT::decode($token);
        } catch (\Exception $e) {
            throw new AuthException("Invalid or expired token", []);
        }

        if (!isset($payload['user_id']) || !isset($payload['role_id'])) {
            throw new AuthException("Malformed token payload");
        }

        $_SERVER['auth_user_id'] = $payload['user_id'];
        if (isset($payload['student_id'])) {
            $_SERVER['auth_student_id'] = (int) $payload['student_id'];
        }
        $_SERVER['auth_role'] = $payload['role_id'];
        $_SERVER['auth_email'] = $payload['email'] ?? null;

        // Device binding enforcement — STUDENTS ONLY. Fathers/admins pass
        // through untouched, so this line being present on every protected
        // route is intentionally harmless for them.
        if ((int) $payload['role_id'] === self::STUDENT_ROLE_ID) {
            $deviceId = $this->extractDeviceId();
            $this->deviceBindingService->verifyForRequest((int) $payload['user_id'], $deviceId);
        }
    }

    private function extractToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) return null;
        return substr($authHeader, 7);
    }

    private function extractDeviceId(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        return $headers[self::DEVICE_HEADER] ?? $headers['x-device-id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? null);
    }
} 