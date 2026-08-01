<?php
namespace App\Exceptions;

  use App\Exceptions\AppException;

class NotFoundException extends AppException
{
    public function __construct(
        string $message = 'Resource not found',
        array $errors = []
    ) {
        parent::__construct(
            $message,
            404,
            'NOT_FOUND',
            $errors
        );
    }
}  


?>  