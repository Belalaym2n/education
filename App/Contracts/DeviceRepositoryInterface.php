<?php

namespace App\Contracts;

interface DeviceRepositoryInterface
{
    public function findByToken(string $token): ?array;

    /** @return array<int, array<string, mixed>> */
    public function findActiveTokensForUsers(array $userIds): array;

    public function upsert(int $userId, string $token, string $platform, ?string $deviceIdentifier, ?string $appVersion): int;

     public function deactivate(int $deviceId): int;

    public function deactivateByToken(string $token): int;

    public function touchLastUsed(int $deviceId): void;

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $userId): array;
}