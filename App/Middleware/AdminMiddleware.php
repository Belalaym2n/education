<?php

namespace App\Middleware;

use App\Exceptions\ForbiddenException;
use App\Exceptions\AuthException;

class AdminMiddleware
{
    public function handle(): void
    {
        // AuthMiddleware should run before this
        // and set auth_role.
        $role = $_SERVER['auth_role'] ?? null;

        if ($role === null) {
            throw new AuthException("Unauthorized", []);
        }

        if ((int) $role !== 3) {
            throw new ForbiddenException();
        }
    }
}