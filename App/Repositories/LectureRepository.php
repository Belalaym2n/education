<?php

namespace App\Repositories;

use App\Contracts\LectureRepositoryInterface;
use App\Database\Database;

class LectureRepository implements LectureRepositoryInterface
{ 
    private Database $db;
    public function getNextOrderNumber(int $stageId): int
    {
        $row = $this->db->selectOne(
            'SELECT MAX(order_number) AS max_order FROM lectures WHERE stage_id = ? AND deleted_at IS NULL',
            [$stageId]
        );

        return (int) ($row['max_order'] ?? 0) + 1;
    }
    /**
     * Whitelisted sortable columns — never interpolate raw user input into ORDER BY.
     */
    private const SORTABLE_COLUMNS = [
        'created_at',
        'order_number',
        'title',
        'price',
        'published_at',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT l.*, s.name AS stage_name
             FROM lectures l
             LEFT JOIN stages s ON s.id = l.stage_id
             WHERE l.id = ? AND l.deleted_at IS NULL",
            [$id]
        );
    }
 
    public function findWithTrashed(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM lectures WHERE id = ?",
            [$id]
        );
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            "SELECT l.*, s.name AS stage_name
             FROM lectures l
             LEFT JOIN stages s ON s.id = l.stage_id
             WHERE l.slug = ? AND l.deleted_at IS NULL",
            [$slug]
        );
    }

    public function allForAdmin(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        $sort = in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'created_at';
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $where = ['l.deleted_at IS NULL'];
        $params = [];

        if (isset($filters['stage_id'])) {
            $where[] = 'l.stage_id = ?';
            $params[] = (int) $filters['stage_id'];
        }

        if (isset($filters['is_published'])) {
            $where[] = 'l.is_published = ?';
            $params[] = (int) $filters['is_published'];
        }

        if (!empty($filters['search'])) {
            $where[] = 'l.title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = $this->db->selectOne(
            "SELECT COUNT(*) AS total FROM lectures l WHERE $whereSql",
            $params
        );

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $rows = $this->db->query(
            "SELECT l.*, s.name AS stage_name
             FROM lectures l
             LEFT JOIN stages s ON s.id = l.stage_id
             WHERE $whereSql
             ORDER BY l.$sort $order
             LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'data' => $rows,
            'total' => (int) ($total['total'] ?? 0),
        ];
    }

    public function allForStudent(int $stageId, array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        $sort = in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'order_number';
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        $where = [
            'l.deleted_at IS NULL',
            'l.is_published = 1',
            'l.stage_id = ?',
        ];
        $params = [$stageId];

        if (!empty($filters['search'])) {
            $where[] = 'l.title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = $this->db->selectOne(
            "SELECT COUNT(*) AS total FROM lectures l WHERE $whereSql",
            $params
        );

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $rows = $this->db->query(
            "SELECT l.id, l.stage_id, l.title, l.slug, l.description, l.youtube_url,
                    l.thumbnail_url, l.duration_seconds, l.order_number, l.price,
                    l.is_free, l.published_at, s.name AS stage_name
             FROM lectures l
             LEFT JOIN stages s ON s.id = l.stage_id
             WHERE $whereSql
             ORDER BY l.$sort $order
             LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'data' => $rows,
            'total' => (int) ($total['total'] ?? 0),
        ];
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('lectures', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('lectures', $data, 'id = ?', [$id]);
    }

     public function softDelete(int $id): int
    {
        return $this->db->update('lectures', ['deleted_at' => date('Y-m-d H:i:s')],  'id = ?', [$id]);
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM lectures WHERE slug = ?';
        $params = [$slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return $this->db->selectOne($sql, $params) !== null;
    }

    public function orderNumberExistsInStage(int $stageId, int $orderNumber, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM lectures WHERE stage_id = ? AND order_number = ? AND deleted_at IS NULL';
        $params = [$stageId, $orderNumber];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return $this->db->selectOne($sql, $params) !== null;
    }
     public function stageExists(int $stageId): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM stages WHERE id = ?',
            [$stageId]
        ) !== null;
    } 

    /**
     * Student-facing list: published lectures only, with the same columns
     * as allForStudent() (no internal fields such as created_by).
     */
    public function findPublishedByStageId(int $stageId): array
    {
        return $this->db->query(
            "SELECT
                l.id, l.stage_id, l.title, l.slug, l.description, l.youtube_url,
                l.thumbnail_url, l.duration_seconds, l.order_number, l.price,
                l.is_free, l.published_at, s.name AS stage_name
            FROM lectures l
            INNER JOIN stages s ON s.id = l.stage_id
            WHERE l.stage_id = ?
              AND l.deleted_at IS NULL
              AND l.is_published = 1
            ORDER BY l.order_number ASC",
            [$stageId]
        );
    }

    public function findByStageId(int $stageId): array
    {
        return $this->db->query(
            "SELECT
            l.*,
            s.name AS stage_name
        FROM lectures l
        INNER JOIN stages s
            ON s.id = l.stage_id
        WHERE 
            l.stage_id = ?
            AND l.deleted_at IS NULL
        ORDER BY l.order_number ASC",
            [$stageId]
        );
    }
}
  