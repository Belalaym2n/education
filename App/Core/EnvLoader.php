<?php
// App/Core/EnvLoader.php

namespace App\Core;

use App\Exceptions\AppException;

class EnvLoader
{ 
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return; // ما نحمّلش الملف أكتر من مرة في نفس الـ request
        }

        if (!file_exists($path)) {
            throw new AppException(
                "Environment file not found at: $path",
                500,
                'ENV_FILE_MISSING'
            );
        }

        $lines = file($path,
         FILE_IGNORE_NEW_LINES |
          FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // تجاهل الكومنتات والأسطر الفاضية
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = 
            explode('=', $line, 2);

            $name = trim($name);
            $value = trim($value);

            // شيل الـ quotes لو موجودة
            $value = trim($value, '"\'');

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        self::$loaded = true;
    }


    public static function 
    get(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        // تحويل القيم النصية لأنواع منطقية
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }

    public static function require(string $key)
    {
        $value = self::get($key);

        if ($value === null) {
            throw new AppException(
                "Missing required environment variable: $key",
                500,
                'ENV_VAR_MISSING'
            );
        }

        return $value;
    } 
}  