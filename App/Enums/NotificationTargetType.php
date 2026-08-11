<?php

namespace App\Enums;

final class NotificationTargetType
{ 
    public const ALL = 'all';
    public const STAGE = 'stage'; 
    public const STUDENT = 'student';
   public const PARENT = 'parent';
    public static function fromString(string $value): string
    {
        $allowed = [
            self::ALL,
            self::STAGE,
            self::STUDENT,
              self::PARENT,
        ];

        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid target_type: $value");
        }

        return $value; 
    }
}   