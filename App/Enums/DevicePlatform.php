<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * DevicePlatform
 *
 * PHP 8.0-safe stand-in for a native enum (your SQL dump reports
 * PHP Version: 8.0.30, and native `enum` syntax requires PHP 8.1+).
 *
 * If your actual runtime is 8.1+, replace this whole file with the
 * native-enum version at the bottom of this comment block and drop
 * the class body — the values/behavior are identical either way.
 *
 * Values intentionally match `device_tokens.platform`
 * (ENUM('android','ios','web')) so this can be reused for both the
 * FCM device_tokens feature and the single-device-login feature
 * without a second, drifting definition of "what platforms exist".
 *
 * ---- Native PHP 8.1+ equivalent (for future migration) ----
 * enum DevicePlatform: string
 * {
 *     case Android = 'android';
 *     case Ios     = 'ios';
 *     case Web     = 'web';
 *
 *     public static function fromRequest(?string $value): self
 *     {
 *         $normalized = strtolower(trim((string) $value));
 *         return self::tryFrom($normalized)
 *             ?? throw new \InvalidArgumentException("Invalid device platform: {$value}");
 *     }
 * }
 * -------------------------------------------------------------
 */
final class DevicePlatform
{
    public const ANDROID = 'android';
    public const IOS = 'ios';
    public const WEB = 'web';

    /** @var string */
    private $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * All valid raw values, e.g. for validators or DB enum checks.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return [self::ANDROID, self::IOS, self::WEB];
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim($value)), self::values(), true);
    }

    /**
     * Build a DevicePlatform from a raw request/header/body value.
     * Case-insensitive, trims whitespace.
     *
     * @throws InvalidArgumentException if the value isn't android/ios/web.
     */
    public static function fromRequest(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));

        if (!in_array($normalized, self::values(), true)) {
            throw new InvalidArgumentException(
                "Invalid device platform: " . ($value ?? 'null') .
                ". Expected one of: " . implode(', ', self::values())
            );
        }

        return new self($normalized);
    }

    /**
     * Same as fromRequest(), but returns null instead of throwing
     * when the value is missing/invalid — useful for optional fields
     * (e.g. an admin-created student row with no platform yet).
     */
    public static function tryFromRequest(?string $value): ?self
    {
        if (!self::isValid($value)) {
            return null;
        }

        return new self(strtolower(trim($value)));
    }

    public static function android(): self
    {
        return new self(self::ANDROID);
    }

    public static function ios(): self
    {
        return new self(self::IOS);
    }

    public static function web(): self
    {
        return new self(self::WEB);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isAndroid(): bool
    {
        return $this->value === self::ANDROID;
    }

    public function isIos(): bool
    {
        return $this->value === self::IOS;
    }

    public function isWeb(): bool
    {
        return $this->value === self::WEB;
    }

    public function equals(DevicePlatform $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}