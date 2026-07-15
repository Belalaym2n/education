<?php

 
namespace App\Exceptions;
 use App\Exceptions\AppException;

 class RateLimitException extends AppException
{
    public function __construct(int $retryAfter = 0)
    {
        parent::__construct(
            "Too many requests. Try again in {$retryAfter} seconds.",
            429,
            "RATE_LIMIT_EXCEEDED",
            [
                "retry_after" => $retryAfter
            ]
        );
    }
}