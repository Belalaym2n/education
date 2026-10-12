<?php
// App/Core/Config.php

namespace App\Core;

class Config
{
    private static array $items = [];
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        } 

        self::$items = [
            'app' => [
                'env'   =>
                 EnvLoader::
                 get('APP_ENV', 'production'),
                'debug' => EnvLoader::get('APP_DEBUG', false),
                'url'   => EnvLoader::get(
                    'APP_URL', ''),
            ],
            'database' => [
                'driver'  => EnvLoader::get('DB_DRIVER', 'mysql'),
                'host'    => EnvLoader::require('DB_HOST'),
                'port'    => EnvLoader::get('DB_PORT', 3306),
                'name'    => EnvLoader::require('DB_NAME'),
                'user'    => EnvLoader::require('DB_USER'),
                'pass'    => EnvLoader::get('DB_PASS', ''),
                'charset' => EnvLoader::get('DB_CHARSET', 'utf8mb4'),
            ], 
            'jwt' => [
                'secret' => EnvLoader::require('JWT_SECRET'),
                'expiry' => (int) EnvLoader::get('JWT_EXPIRY', 3600),
            ],
            'rate_limit' => [
                'max'    => (int) EnvLoader::get('RATE_LIMIT_MAX', 60),
                'window' => (int) EnvLoader::get('RATE_LIMIT_WINDOW', 60),
            ],
        ];

        self::$initialized = true;
    }

    public static function get(string $key, $default = null)
    {
        self::init();

        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}