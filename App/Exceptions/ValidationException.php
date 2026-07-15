<?php
// src/Exceptions/ValidationException.php
 namespace App\Exceptions;

  use App\Exceptions\AppException;

class ValidationException extends AppException
{
    protected array $errors;

    public function __construct(array $errors, string $message = "Validation failed")
    {
        parent::__construct($message, 422,    "Validation Error",
            ['errors' => $errors]);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
} ?> 