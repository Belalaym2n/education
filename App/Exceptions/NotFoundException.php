<?php
namespace App\Exceptions;

  use App\Exceptions\AppException;

class NotFoundException extends AppException
{
    public function __construct(
        string $message
         = "Resource not found",
     ) {
        parent::__construct("Resource not found", 404,
         "Resource not found",[
           
         ]
        );
    }
}


?>  