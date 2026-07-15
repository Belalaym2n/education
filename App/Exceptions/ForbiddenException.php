<?php

namespace App\Exceptions;

class ForbiddenException extends AppException
{
    public function __construct(
        string $message = "You do not have permission to perform this action.",
        array $context = []
    ) {
        parent::__construct(
            $message,
            403,
            "FORBIDDEN",
            $context
        );
    }
} 