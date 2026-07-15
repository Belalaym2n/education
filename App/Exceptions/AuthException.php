<?php 
 namespace App\Exceptions;

class AuthException extends AppException
{
    public function __construct(string $message = "Database error occurred", array $context = [])
    {
        parent::__construct($message, 401,  "ACCOUNT_INACTIVE",[
           $message
         ]);
    }
}?>   