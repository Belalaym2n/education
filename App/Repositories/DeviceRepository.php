<?php

namespace App\Repositories;

use App\Contracts\DeviceRepositoryInterface;
use App\Database\Database;

/**
 * Persistence for FCM device tokens. Handles upsert semantics so that
 * re-login / reinstall / token-refresh never create duplicate active rows
 * for the same physical device.
 */
final class DeviceRepository implements DeviceRepositoryInterface
{
    private const TABLE = 'device_tokens';
     private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }
    public function findByToken(string $token): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM " . self::TABLE . " WHERE fcm_token = ? AND deleted_at IS NULL",
            [$token]
        );
    }

    public function findActiveTokensForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $userIds = array_map('intval', $userIds);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $sql = "SELECT id, user_id, fcm_token, platform
                FROM " . self::TABLE . "
                WHERE user_id IN ($placeholders) AND is_active = 1 AND deleted_at IS NULL";

        return $this->db->query($sql, $userIds);
    }

    public function upsert(int $userId, string $token, string $platform, ?string $deviceIdentifier, ?string $appVersion): int
    {
        $existing = $this->findByToken($token);

        if ($existing !== null) {
            $this->db->update(self::TABLE, [
                'user_id' => $userId,
                'platform' => $platform,
                'device_identifier' => $deviceIdentifier,
                'app_version' => $appVersion,
                'is_active' => 1,
                'last_used_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $existing['id']]);

            return (int) $existing['id'];
        }

        return (int) $this->db->insert(self::TABLE, [
            'user_id' => $userId,
            'fcm_token' => $token,
            'platform' => $platform,
            'device_identifier' => $deviceIdentifier,
            'app_version' => $appVersion,
            'is_active' => 1,
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deactivate(int $deviceId): int
    {
        return $this->db->update(self::TABLE, ['is_active' => 0], 'id = ?', [$deviceId]);
    }

    public function deactivateByToken(string $token): int
    {
        return $this->db->execute(
            "UPDATE " . self::TABLE . " SET is_active = 0 WHERE fcm_token = ?",
            [$token]
        );
    }

    public function touchLastUsed(int $deviceId): void
    {
        $this->db->update(self::TABLE, ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$deviceId]);
    }

    public function listForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE user_id = ? AND deleted_at IS NULL
             ORDER BY last_used_at DESC",
            [$userId]
        );
    }
}
