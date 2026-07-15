<?php
// App/Middleware/RoleMiddleware.php

namespace App\Middleware;

use App\Exceptions\ForbiddenException;
use App\Exceptions\AuthException;

class RoleMiddleware
{
    private array $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function handle(): void
    {
        $role = $_SERVER['auth_role'] ?? null;

        if (!$role) {
            throw new AuthException("Unauthorized", 'AUTH_REQUIRED');
        }

        if (!in_array($role, $this->allowedRoles, true)) {
            throw new ForbiddenException("Access denied for role: $role");
        }
    }
}