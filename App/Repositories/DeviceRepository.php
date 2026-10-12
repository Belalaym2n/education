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

    /**
     * Single atomic statement keyed on `uq_device_token`. The previous
     * find-then-insert raced when the app registered the same new token twice
     * at once (app start + login), and the loser failed with a 1062 duplicate
     * error. LAST_INSERT_ID(id) makes the update path return the existing id.
     */
    public function upsert(int $userId, string $token, string $platform, ?string $deviceIdentifier, ?string $appVersion): int
    {
        $this->db->execute(
            "INSERT INTO " . self::TABLE . "
                (user_id, fcm_token, platform, device_identifier, app_version, is_active, last_used_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                user_id = VALUES(user_id),
                platform = VALUES(platform),
                device_identifier = VALUES(device_identifier),
                app_version = VALUES(app_version),
                is_active = 1,
                deleted_at = NULL,
                last_used_at = NOW()",
            [$userId, $token, $platform, $deviceIdentifier, $appVersion]
        );

        return (int) $this->db->selectOne("SELECT LAST_INSERT_ID() AS id")['id'];
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

    public function deactivateByTokenForUser(string $token, int $userId): int
    {
        return $this->db->execute(
            "UPDATE " . self::TABLE . " SET is_active = 0 WHERE fcm_token = ? AND user_id = ?",
            [$token, $userId]
        );
    }

    public function deactivateOtherTokensForDevice(int $userId, string $deviceIdentifier, int $keepDeviceId): int
    {
        return $this->db->execute(
            "UPDATE " . self::TABLE . " SET is_active = 0
             WHERE user_id = ? AND device_identifier = ? AND id <> ? AND is_active = 1",
            [$userId, $deviceIdentifier, $keepDeviceId]
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
