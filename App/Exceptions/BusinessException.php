<?php

namespace App\Exceptions;

use App\Exceptions\AppException;

class BusinessException extends AppException
{
    public function __construct(
        string $message = "Business rule violation",
        string $errorCode = "BUSINESS_ERROR",
        array $details = []
    ) {
        parent::__construct(
            $message,
            400,
           "Business rule violation",[
           $message 
         ] 
        );
    }
}

?>