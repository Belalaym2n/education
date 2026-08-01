<?php

namespace App\Helpers;

final class ResponseHelper
{
    public static function success(
        mixed $data = null,
        string $message = 'Success',
     ): array {
        return [
            'success' => true,
             'message' => $message,
            'data' => $data,
        ];
    }

    public static function created(
        mixed $data = null,
        string $message = 'Created successfully'
    ): array {
        return self::success($data, $message  );
    }

    public static function updated(
        mixed $data = null,
        string $message = 'Updated successfully'
    ): array {
        return self::success($data, $message);
    }

    public static function deleted(
        string $message = 'Deleted successfully'
    ): array {
        return [
            'success' => true,
             'message' => $message,
        ];
    }
 
    public static function noContent(): array
    {
        return [
            'success' => true,
             'message' => 'No content',
        ];
    }
}