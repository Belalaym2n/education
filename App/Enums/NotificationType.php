<?php

namespace App\Enums;

final class NotificationType
{
    public const SYSTEM = 'system';
    public const LECTURE = 'lecture';
    public const PROMOTIONAL = 'promotional';
    public const EDUCATIONAL = 'educational';
    public const WARNING = 'warning';
    public const ATTENDANCE = 'attendance'; 

    public static function fromString(string $value): string
    {
        $allowed = [ 
            self::SYSTEM,
            self::LECTURE,
            self::PROMOTIONAL,
              self::ATTENDANCE,
            self::EDUCATIONAL,
            self::WARNING,
        ]; 

        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid notification_type: $value");
        }

        return $value;
    }
} 