<?php
// src/Exceptions/AppException.php

 namespace App\Exceptions;

 

class AppException extends \Exception
{
 
    protected int $statusCode;
    protected string $errorCode;
    protected array $context;

public function __construct(
    string $message,
    int $statusCode = 500,
    string $errorCode = 'INTERNAL_ERROR',
    array $context = []
) {
        parent::__construct($message);

        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->context = $context;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    } 
}