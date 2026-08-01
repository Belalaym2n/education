<?php

namespace App\Repositories;

use App\Contracts\StageRepositoryInterface;
use App\Database\Database;

/**
 * StageRepository
 *
 * Talks to the `stages` table ONLY. No business rules here — that belongs
 * in StageService. Every query is parameterized; nothing from user input
 * is ever concatenated into SQL. Sort column/direction are whitelisted
 * before being interpolated (PDO cannot bind identifiers or ORDER BY
 * direction), which is the only place raw interpolation is used and it is
 * restricted to a fixed allow-list.
 */
class StageRepository implements StageRepositoryInterface
{
    private Database $db;

    private const ALLOWED_SORT_COLUMNS = ['id', 'name', 'order_number', 'created_at', 'updated_at'];
    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM stages WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    public function findById(int $id, bool $withTrashed = false): ?array
    {
        if ($withTrashed) {
            return $this->db->selectOne(
                
            "SELECT * FROM stages WHERE id = ?", [$id]);
        }

        return $this->find($id);
    }

    public function findByName(string $name, ?int $excludeId = null): ?array
    {
        if ($excludeId !== null) {
            return $this->db->selectOne(
                "SELECT * FROM stages WHERE name = ? AND deleted_at IS NULL AND id != ?",
                [$name, $excludeId]
            );
        }

        return $this->db->selectOne(
            "SELECT * FROM stages WHERE name = ? AND deleted_at IS NULL",
            [$name]
        );
    }
 
    public function all(bool $withTrashed = false): array
    {
        // $sql = $withTrashed 
        //     ? "SELECT * FROM stages ORDER BY order_number ASC"
        //     : "SELECT * FROM stages WHERE deleted_at IS NULL ORDER BY order_number ASC";

        $sql="SELECT * FROM stages WHERE deleted_at IS NULL ORDER BY order_number ASC";

        return $this->db->query($sql);
    }

      public function getYears(bool $withTrashed = false): array  
    {
        // $sql = $withTrashed 
        //     ? "SELECT * FROM stages ORDER BY order_number ASC"
        //     : "SELECT * FROM stages WHERE deleted_at IS NULL ORDER BY order_number ASC";

        $sql="SELECT * FROM academic_years";

        return $this->db->query($sql); 
    }
 
 
    public function paginate(int $page, int $perPage, array $options = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhere($options);
        [$sortColumn, $sortDirection] = $this->resolveSort(
            (string) ($options['sort_by'] ?? 'order_number'),
            (string) ($options['sort_direction'] ?? 'ASC')
        );

        $sql = "SELECT * FROM stages WHERE $where ORDER BY $sortColumn $sortDirection LIMIT $perPage OFFSET $offset";

        $items = $this->db->query($sql, $params);
        $total = $this->count($options);

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    public function count(array $options = []): int
    {
        [$where, $params] = $this->buildWhere($options);
        $row = $this->db->selectOne("SELECT COUNT(*) AS cnt FROM stages WHERE $where", $params);

        return (int) ($row['cnt'] ?? 0);
    }

    public function search(string $keyword, bool $withTrashed = false): array
    {
        $sql = $withTrashed
            ? "SELECT * FROM stages WHERE name LIKE ? ORDER BY order_number ASC"
            : "SELECT * FROM stages WHERE name LIKE ? AND deleted_at IS NULL ORDER BY order_number ASC";

        return $this->db->query($sql, ['%' . $keyword . '%']);
    }

    public function sort(string $column, string $direction = 'ASC', bool $withTrashed = false): array
    {
        [$sortColumn, $sortDirection] = $this->resolveSort($column, $direction);

        $sql = $withTrashed
            ? "SELECT * FROM stages ORDER BY $sortColumn $sortDirection"
            : "SELECT * FROM stages WHERE deleted_at IS NULL ORDER BY $sortColumn $sortDirection";

        return $this->db->query($sql);
    }

    public function filter(array $criteria): array
    {
        [$where, $params] = $this->buildWhere($criteria);

        return $this->db->query("SELECT * FROM stages WHERE $where ORDER BY order_number ASC", $params);
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('stages', $data);
    }

    public function update(int $id, array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        return $this->db->update('stages', $data, $id);
    }

    public function delete(int $id): int
    {
        return $this->db->update('stages', ['deleted_at' => date('Y-m-d H:i:s')], $id);
    }

    public function restore(int $id): int
    {
        return $this->db->update('stages', ['deleted_at' => null], $id);
    }

    public function exists(int $id): bool
    {
        return $this->find($id) !== null;
    }

    public function existsByName(string $name, ?int $excludeId = null): bool
    {
        return $this->findByName($name, $excludeId) !== null;
    }

    public function existsByOrder(int $orderNumber, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->selectOne(
                "SELECT id FROM stages WHERE order_number = ? AND deleted_at IS NULL AND id != ?",
                [$orderNumber, $excludeId]
            );
        } else {
            $row = $this->db->selectOne(
                "SELECT id FROM stages WHERE order_number = ? AND deleted_at IS NULL",
                [$orderNumber]
            );
        }

        return $row !== null;
    }

    public function studentsCount(int $stageId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM students WHERE stage_id = ?",
            [$stageId]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    public function nextOrderNumber(): int
    {
        $row = $this->db->selectOne("SELECT MAX(order_number) AS max_order FROM stages");

        return (int) ($row['max_order'] ?? 0) + 1;
    }

    /**
     * Builds a safe WHERE clause + bound params from a filter options array.
     * Every value goes through a placeholder — only column names are ever
     * hard-coded (never taken from $options directly).
     *
     * @return array{0: string, 1: array}
     */
    private function buildWhere(array $options): array
    {
        $conditions = [];
        $params = [];

        $withTrashed = (bool) ($options['with_trashed'] ?? false);
        $onlyTrashed = (bool) ($options['only_trashed'] ?? false);

        if ($onlyTrashed) {
            $conditions[] = 'deleted_at IS NOT NULL';
        } elseif (!$withTrashed) {
            $conditions[] = 'deleted_at IS NULL';
        }

        if (!empty($options['name'])) {
            $conditions[] = 'name LIKE ?';
            $params[] = '%' . $options['name'] . '%';
        }

        if (isset($options['order_number']) && $options['order_number'] !== '') {
            $conditions[] = 'order_number = ?';
            $params[] = (int) $options['order_number'];
        }

        if (empty($conditions)) {
            $conditions[] = '1 = 1';
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Whitelists sort column/direction. PDO cannot bind identifiers, so this
     * is the only defensible way to safely allow dynamic ORDER BY.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSort(string $column, string $direction): array
    {
        $column = in_array($column, self::ALLOWED_SORT_COLUMNS, true) ? $column : 'order_number';
        $direction = strtoupper($direction);
        $direction = in_array($direction, self::ALLOWED_DIRECTIONS, true) ? $direction : 'ASC';

        return [$column, $direction];
    }
}