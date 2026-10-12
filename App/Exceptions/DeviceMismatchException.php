<?php

namespace App\Exceptions;

/**
 * Thrown when an authenticated student's request device does not match
 * their bound device. Distinct from AuthException (bad/missing token) —
 * this is an authorization failure, not an authentication failure
 * (see requirement: don't confuse the two).
 */
class DeviceMismatchException extends AppException
{
    public function __construct(string $message = 'This account is already registered on another device.')
    {
        parent::__construct($message, 403, 'DEVICE_MISMATCH');
    }
}