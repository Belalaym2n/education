<?php

namespace App\Services;

use App\Contracts\DeviceRepositoryInterface;
use App\Core\Logger;
use App\Enums\DevicePlatform;
use App\Exceptions\ValidationException;

/**
 * Manages the lifecycle of a user's push-notification devices:
 * registration on login, token refresh, logout, and listing.
 */
final class DeviceService
{
    /** Matches `device_tokens.fcm_token` VARCHAR(255). */
    private const MAX_TOKEN_LENGTH = 255;

    // Plain properties: `readonly` needs PHP 8.1, the server runs 8.0.
    private DeviceRepositoryInterface $deviceRepo;
    private Logger $logger;

    public function __construct(DeviceRepositoryInterface $deviceRepo, Logger $logger)
    {
        $this->deviceRepo = $deviceRepo;
        $this->logger = $logger;
    }

    public function registerDevice(int $userId, string $token, string $platform, ?string $deviceIdentifier = null, ?string $appVersion = null): int
    {
        $platformValue = DevicePlatform::tryFromRequest($platform);
        if ($platformValue === null) {
            throw new ValidationException(['platform' => 'platform must be one of: ' . implode(', ', DevicePlatform::values())]);
        }

        $token = trim($token);
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new ValidationException(['fcm_token' => 'fcm_token is invalid']);
        }

        $deviceIdentifier = $deviceIdentifier !== null ? trim($deviceIdentifier) : null;
        if ($deviceIdentifier === '') {
            $deviceIdentifier = null;
        }

        $deviceId = $this->deviceRepo->upsert($userId, $token, $platformValue->value(), $deviceIdentifier, $appVersion);

        // Token rotation on the same installation leaves the old token's row
        // active; retire it so the user is not pushed to a dead token.
        if ($deviceIdentifier !== null) {
            $this->deviceRepo->deactivateOtherTokensForDevice($userId, $deviceIdentifier, $deviceId);
        }

        $this->logger->info('Device registered', ['user_id' => $userId, 'device_id' => $deviceId, 'platform' => $platformValue->value()]);

        return $deviceId;
    }

    /**
     * Only deactivates the token if it belongs to the calling user, so one
     * account cannot switch off another account's device.
     */
    public function unregisterDevice(int $userId, string $token): bool
    {
        $affected = $this->deviceRepo->deactivateByTokenForUser(trim($token), $userId);
        $this->logger->info('Device unregistered', ['user_id' => $userId, 'affected' => $affected]);

        return $affected > 0;
    }

    public function listDevices(int $userId): array
    {
        return $this->deviceRepo->listForUser($userId);
    }

    /** @return int[] */
    public function getActiveTokensForUsers(array $userIds): array
    {
        $rows = $this->deviceRepo->findActiveTokensForUsers($userIds);
        return array_map(fn($row) => $row['fcm_token'], $rows);
    }

    public function deactivateInvalidToken(string $token): void
    {
        $this->deviceRepo->deactivateByToken($token);
        $this->logger->warning('Deactivated invalid FCM token');
    }
}