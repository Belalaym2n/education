<?php

namespace App\Middleware;

use App\Exceptions\ForbiddenException;
use App\Exceptions\AuthException;

class AdminMiddleware
{
    public function handle(): void
    {
        // AuthMiddleware المفروض يكون شغال قبله ويحط auth_role
        $role = $_SERVER['auth_role'] ?? null;

        if (!$role) {
            throw new AuthException("Unauthorized", [],
            ); 
        }

         if ($role !== '3') {
            throw new ForbiddenException();
        }
    }
} 