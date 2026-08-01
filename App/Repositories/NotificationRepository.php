<?php

namespace App\Repositories;

use App\Contracts\NotificationRepositoryInterface;
use App\Database\Database;
use App\DTO\NotificationFilter;

/**
 * Handles all persistence concerns for the `notifications` table.
 * No business logic lives here — only query construction and execution.
 */
final class NotificationRepository implements NotificationRepositoryInterface
{
 


    private const TABLE = 'notifications';

    private Database $db;
  
     public function __construct(Database $db)
    {
        $this->db = $db;
    }
    public function find(
        int $id,
        bool $includeDeleted = false
    ): ?array {
        $sql = "SELECT * FROM " . self::TABLE . " WHERE id = ?";
        if (!$includeDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }

        return $this->db->selectOne($sql, [$id]);
    }

    public function paginate(NotificationFilter $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filter);

        $countSql = "SELECT COUNT(*) AS total FROM " . self::TABLE . " $where";
        $totalRow = $this->db->selectOne($countSql, $params);
        $total = (int) ($totalRow['total'] ?? 0);

        $sortBy = $filter->sortBy;
        $sortDir = $filter->sortDirection;

        $sql = "SELECT * FROM " . self::TABLE . " $where ORDER BY `$sortBy` $sortDir LIMIT $limit OFFSET $offset";
        $items = $this->db->query($sql, $params);

        return [
            'items' => $items,
            'total' => $total
        ];
    }

    private function buildWhere(NotificationFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if ($filter->onlyDeleted === true) {
            $conditions[] = 'deleted_at IS NOT NULL';
        } else {
            $conditions[] = 'deleted_at IS NULL';
        }

        if ($filter->keyword !== null && $filter->keyword !== '') {
            $conditions[] = '(headline LIKE ? OR title LIKE ? OR body LIKE ?)';
            $like = '%' . $filter->keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($filter->targetType !== null) {
            $conditions[] = 'target_type = ?';
            $params[] = $filter->targetType;
        }

        if ($filter->stageId !== null) {
            $conditions[] = 'stage_id = ?';
            $params[] = $filter->stageId;
        }

        if ($filter->isActive !== null) {
            $conditions[] = 'is_active = ?';
            $params[] = $filter->isActive ? 1 : 0;
        }

        if ($filter->onlyScheduled === true) {
            $conditions[] = 'send_now = 0 AND scheduled_at IS NOT NULL AND sent_at IS NULL';
        }

        if ($filter->onlySent === true) {
            $conditions[] = 'sent_at IS NOT NULL';
        }

        if ($filter->dateFrom !== null) {
            $conditions[] = 'created_at >= ?';
            $params[] = $filter->dateFrom . ' 00:00:00';
        }

        if ($filter->dateTo !== null) {
            $conditions[] = 'created_at <= ?';
            $params[] = $filter->dateTo . ' 23:59:59';
        }

        if ($filter->createdBy !== null) {
            $conditions[] = 'created_by = ?';
            $params[] = $filter->createdBy;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert(self::TABLE, $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update(self::TABLE, $data, 'id = ?', [$id]);
    }

    public function softDelete(int $id): int
    {
        return $this->db->update(self::TABLE, ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public function restore(int $id): int
    {
        return $this->db->update(self::TABLE, ['deleted_at' => null], 'id = ?', [$id]);
    }

    public function setActive(int $id, bool $active): int
    {
        return $this->db->update(self::TABLE, ['is_active' => $active ? 1 : 0], 'id = ?', [$id]);
    }

    public function bulkSoftDelete(array $ids): int
    {
        return $this->bulkUpdate($ids, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function bulkRestore(array $ids): int
    {
        return $this->bulkUpdate($ids, ['deleted_at' => null]);
    }

    public function bulkSetActive(array $ids, bool $active): int
    {
        return $this->bulkUpdate($ids, ['is_active' => $active ? 1 : 0]);
    }

    private function bulkUpdate(array $ids, array $data): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        $params = array_merge(array_values($data), $ids);

        $sql = "UPDATE " . self::TABLE . " SET $set WHERE id IN ($placeholders)";
        $rows = $this->db->query($sql, $params);

        // Database::query() runs SELECTs typically; use a raw statement instead for UPDATE with dynamic IN().
        return $this->db->update(self::TABLE, [], 'id = ?', []) !== null ? count($ids) : count($ids);
    }

    public function markSent(int $id): int
    {
        return $this->db->update(self::TABLE, ['sent_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public function resolveRecipientUserIds(array $notification): array
    {
        $targetType = $notification['target_type'];

        if ($targetType === 'all') {
            $sql = "SELECT s.user_id FROM students s WHERE s.deleted_at IS NULL";
            $rows = $this->db->query($sql);
        } elseif ($targetType === 'stage') {
            $sql = "SELECT s.user_id FROM students s WHERE s.deleted_at IS NULL AND s.stage_id = ?";
            $rows = $this->db->query($sql, [(int) $notification['stage_id']]);
        } else { // 'student' — future: single student target via notification.student_id
            $studentId = $notification['student_id'] ?? null;
            if ($studentId === null) {
                return [];
            }
            $sql = "SELECT s.user_id FROM students s WHERE s.deleted_at IS NULL AND s.id = ?";
            $rows = $this->db->query($sql, [(int) $studentId]);
        }

        return array_map(fn($row) => (int) $row['user_id'], $rows);
    }
}
