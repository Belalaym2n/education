<?php

namespace App\Middleware;

use App\Exceptions\AuthException;
use App\Exceptions\ForbiddenException;

/**
 * Allows only role_id = 1 (student). Registered in Router as 'student'.
 * Added instead of relying on `role:1` because RoleMiddleware was not part
 * of the reviewed code and its comparison type could not be verified.
 */
class StudentMiddleware
{
    private const STUDENT_ROLE_ID = 1;

    public function handle(): void
    {
        $role = $_SERVER['auth_role'] ?? null;

        if ($role === null || $role === '') {
            throw new AuthException("Unauthorized");
        }

        if ((int) $role !== self::STUDENT_ROLE_ID) {
            throw new ForbiddenException();
        }
    }
}
