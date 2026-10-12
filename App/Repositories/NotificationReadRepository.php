<?php

namespace App\Repositories;

use App\Contracts\NotificationReadRepositoryInterface;
use App\Database\Database;

/**
 * Manages per-user read-state for notifications (`notification_recipients`).
 */
final class NotificationReadRepository implements NotificationReadRepositoryInterface
{
    private const TABLE = 'notification_recipients';

       private Database $db;
  
     public function __construct(Database $db)
    {
        $this->db = $db;
    } 

    public function bulkCreatePending(int $notificationId, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }
 
        $rows = [];
        $placeholders = [];
        foreach ($userIds as $userId) {
            $placeholders[] = '(?, ?, 0, NOW())';
            $rows[] = $notificationId;
            $rows[] = (int) $userId;
        }

        $sql = "INSERT IGNORE INTO " . self::TABLE . " (notification_id, user_id, is_read, created_at) VALUES "
            . implode(', ', $placeholders);

        $this->db->execute($sql, $rows);
    }

    public function markAsRead(int $notificationId, int $userId, ?int $deviceId = null): int
    {
        $sql = "UPDATE " . self::TABLE . "
                SET is_read = 1, read_at = NOW(), read_device_id = ?
                WHERE notification_id = ? AND user_id = ? AND is_read = 0";
 
        return $this->db->execute($sql, [$deviceId, $notificationId, $userId]);
    }

    public function markAllAsRead(int $userId): int
    {
        $sql = "UPDATE " . self::TABLE . "
                SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND is_read = 0";

        return $this->db->execute($sql, [$userId]);
    }

    /**
     * Unread notifications that the user can actually see — the same
     * visibility rules as listForUser() (not removed locally, notification
     * not deleted and still active). Previously deleted/disabled
     * notifications were counted, so the badge could show unread items that
     * never appear in the list.
     */
    public function unreadCount(int $userId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt
             FROM " . self::TABLE . " nr
             INNER JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.user_id = ? AND nr.is_read = 0 AND nr.is_deleted_locally = 0
               AND n.deleted_at IS NULL AND n.is_active = 1",
            [$userId]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * The user's recipient row for a notification, only if the notification
     * was sent to this user and is still visible to them (same rules as the
     * list). Returns null otherwise — used to authorise the details endpoint.
     */
    public function findVisibleForUser(int $notificationId, int $userId): ?array
    {
        return $this->db->selectOne(
            "SELECT nr.is_read, nr.read_at
             FROM " . self::TABLE . " nr
             INNER JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.notification_id = ? AND nr.user_id = ?
               AND nr.is_deleted_locally = 0
               AND n.deleted_at IS NULL AND n.is_active = 1",
            [$notificationId, $userId]
        );
    }

    public function listForUser(int $userId, bool $unreadOnly, int $limit, int $offset): array
    {
        $condition = $unreadOnly ? 'AND nr.is_read = 0' : '';

        $countSql = "SELECT COUNT(*) AS total
                     FROM " . self::TABLE . " nr
                     INNER JOIN notifications n ON n.id = nr.notification_id
                     WHERE nr.user_id = ? AND nr.is_deleted_locally = 0
                       AND n.deleted_at IS NULL AND n.is_active = 1
                       $condition";
        $total = (int) ($this->db->selectOne($countSql, [$userId])['total'] ?? 0);

        $sql = "SELECT
                    n.id, n.headline,  n.body, n.image_url,
                    n.deep_link, n.button_text, n.button_action,
                    n.external_url, n.notification_type,
                    nr.is_read, nr.read_at, n.created_at
                FROM " . self::TABLE . " nr
                INNER JOIN notifications n ON n.id = nr.notification_id
                WHERE nr.user_id = ? AND nr.is_deleted_locally = 0
                  AND n.deleted_at IS NULL AND n.is_active = 1
                  $condition
                ORDER BY n.created_at DESC, n.id DESC
                LIMIT $limit OFFSET $offset";

        $items = array_map([self::class, 'normalizeListItem'], $this->db->query($sql, [$userId]));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * PDO on PHP 8.0 returns every column as a string; the mobile contract
     * is `id` int and `is_read` bool.
     */
    private static function normalizeListItem(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_read'] = (int) $row['is_read'] === 1;

        return $row;
    }

    public function softDeleteLocally(int $notificationId, int $userId): int
    {
        $sql = "UPDATE " . self::TABLE . "
                SET is_deleted_locally = 1
                WHERE notification_id = ? AND user_id = ?";

        return $this->db->execute($sql, [$notificationId, $userId]);
    }
}