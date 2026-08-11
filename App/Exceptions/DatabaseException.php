<?php
namespace App\Exceptions;
  use App\Exceptions\AppException;

class DatabaseException extends AppException
{
    public function __construct(string $message = "Database error occurred", array $context = [])
    {
        parent::__construct($message, 500, "Database failure",[]);
    }
} ?>