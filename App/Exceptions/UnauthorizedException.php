<?php
// src/Exceptions/UnauthorizedException.php
 namespace App\Exceptions;

 
class UnauthorizedException extends AppException
{
    public function __construct(string $message = "Unauthorized")
    {
        parent::__construct($message, 401);
    }
}?>