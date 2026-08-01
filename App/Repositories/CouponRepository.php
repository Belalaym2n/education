<?php

namespace App\Repositories;

use App\Contracts\CouponRepositoryInterface;
use App\Constants\CouponConstants;
use App\Database\Database; 

/**
 * PATCHED VERSION — replace the whole class body with this one.
 * Changes vs. original:
 *   - find()/paginate()/count() now JOIN stages via lectures.stage_id
 *     (adds `stage_id`, `stage_name` to every row).
 *   - buildFilters() fixes `l.name` -> `l.title` (name did not exist),
 *     adds `stageId` param, and replaces the boolean-only status logic
 *     with a `status` enum: active | inactive | expired | fully_used | all.
 *   - New: countByStatus() for the /coupons/summary endpoint.
 *   - New: createMany() for the /coupons/bulk endpoint.
 *   - New: exportAll() for the /coupons/export endpoint (no LIMIT/OFFSET,
 *     capped by CouponConstants::EXPORT_MAX_ROWS).
 */
class CouponRepository implements CouponRepositoryInterface
{
    private Database $db;
 
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private const BASE_SELECT = "
        c.*, 
        l.title AS lecture_name,
        l.price AS lecture_price,
        s.id AS stage_id,
        s.name AS stage_name
    ";

    private const BASE_FROM = "
        FROM coupons c
        INNER JOIN lectures l ON l.id = c.lecture_id
        INNER JOIN stages s ON s.id = l.stage_id
    ";

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT " . self::BASE_SELECT . self::BASE_FROM . "
             WHERE c.id = ? AND c.deleted_at IS NULL",
            [$id]
        );
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM coupons WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
            [$id]
        );
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM coupons WHERE code = ? AND deleted_at IS NULL",
            [$code]
        );
    }

    public function findByCodeForUpdate(string $code): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM coupons WHERE code = ? AND deleted_at IS NULL FOR UPDATE",
            [$code]
        );
    }

    public function findActiveByCodeAndLecture(string $code, int $lectureId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM coupons WHERE code = ? AND lecture_id = ? AND deleted_at IS NULL",
            [$code, $lectureId]
        );
    }

    public function codeExists(string $code): bool
    {
        // Ignores deleted_at on purpose: `code` has a table-wide UNIQUE key
        // that is not soft-delete aware, so a soft-deleted coupon's code
        // must not be reissued either.
        $row = $this->db->selectOne("SELECT id FROM coupons WHERE code = ?", [$code]);
        return $row !== null;
    }

    public function paginate(
        int $limit,
        int $offset,
        ?string $search = null,
        ?int $lectureId = null,
        ?bool $isActive = null,
        string $sortBy = 'created_at',
        string $sortDir = 'DESC',
        ?int $stageId = null,
        ?string $status = null
    ): array {
        [$where, $params] = $this->buildFilters($search, $lectureId, $isActive, $stageId, $status);

        $sortBy = $this->sanitizeSortColumn($sortBy);
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, min($limit, CouponConstants::MAX_PAGE_SIZE));
        $offset = max(0, $offset);

        $sql = "SELECT " . self::BASE_SELECT . self::BASE_FROM . "
                WHERE $where
                ORDER BY c.$sortBy $sortDir
                LIMIT $limit OFFSET $offset";

        return $this->db->query($sql, $params);
    }

    public function count(
        ?string $search = null,
        ?int $lectureId = null,
        ?bool $isActive = null,
        ?int $stageId = null,
        ?string $status = null
    ): int {
        [$where, $params] = $this->buildFilters($search, $lectureId, $isActive, $stageId, $status);

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total " . self::BASE_FROM . " WHERE $where",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Aggregate counts for /coupons/summary — same filters as index, minus
     * pagination, so insight cards are correct across every page.
     * A single query with conditional SUM()s is used to avoid 6 round trips.
     */
    public function summary(
        ?string $search = null,
        ?int $lectureId = null,
        ?int $stageId = null
    ): array {
        // Status is intentionally NOT filtered here — the summary must show
        // the breakdown ACROSS all statuses for the current Stage/Lecture/
        // Search selection; only the "Status" chips visually mirror it.
        [$where, $params] = $this->buildFilters($search, $lectureId, null, $stageId, null);

        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN c.is_active = 1
                         AND (c.expire_at IS NULL OR c.expire_at >= NOW())
                         AND c.times_used < c.max_uses THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN c.is_active = 0 THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN c.expire_at IS NOT NULL AND c.expire_at < NOW() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN c.times_used >= c.max_uses THEN 1 ELSE 0 END) AS fully_used,
                COALESCE(SUM(c.times_used), 0) AS total_uses,
                COALESCE(SUM(c.max_uses - c.times_used), 0) AS remaining_uses
            " . self::BASE_FROM . "
            WHERE $where
        ";

        $row = $this->db->selectOne($sql, $params) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'expired' => (int) ($row['expired'] ?? 0),
            'fully_used' => (int) ($row['fully_used'] ?? 0),
            'total_uses' => (int) ($row['total_uses'] ?? 0),
            'remaining_uses' => (int) ($row['remaining_uses'] ?? 0),
        ];
    }

    /** Unpaginated fetch for Excel export — hard-capped, never the whole table. */
    public function exportAll(
        ?string $search = null,
        ?int $lectureId = null,
        ?bool $isActive = null,
        ?int $stageId = null,
        ?string $status = null,
        string $sortBy = 'created_at',
        string $sortDir = 'DESC'
    ): array {
        [$where, $params] = $this->buildFilters($search, $lectureId, $isActive, $stageId, $status);
        $sortBy = $this->sanitizeSortColumn($sortBy);
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        $cap = CouponConstants::EXPORT_MAX_ROWS;

        $sql = "SELECT " . self::BASE_SELECT . self::BASE_FROM . "
                WHERE $where
                ORDER BY c.$sortBy $sortDir
                LIMIT $cap";

        return $this->db->query($sql, $params);
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('coupons', $data);
    }

    /**
     * Bulk-insert for /coupons/bulk. Caller (service layer) must already be
     * inside a transaction. Falls back to a loop of insert() calls if the
     * Database class has no native multi-row insert — still atomic because
     * the caller wraps this in a transaction, just not a single SQL round trip.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int[] inserted ids, in the same order as $rows
     */
    public function createMany(array $rows): array
    {
        if (method_exists($this->db, 'insertMany')) {
            return $this->db->insertMany('coupons', $rows);
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $this->db->insert('coupons', $row);
        }
        return $ids;
    }

   public function update(int $id, array $data): int
{
    return $this->db->update(
        'coupons',
        $data,
        'id = ?',
        [$id],
    );
}
    public function incrementTimesUsed(int $id): int
    {
        return $this->db->execute(
            "UPDATE coupons SET times_used = times_used + 1 WHERE id = ?",
            [$id]
        );
    }

    public function softDelete(int $id): int
    {
        return $this->db->update('coupons',
         ['deleted_at' => date('Y-m-d H:i:s')]
           ,'id = ?',
        [$id],);  
    }

    public function restore(int $id): int
    {
        return $this->db->update('coupons', ['deleted_at' => null], $id);
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function buildFilters(
        ?string $search,
        ?int $lectureId,
        ?bool $isActive,
        ?int $stageId = null,
        ?string $status = null
    ): array {
        $conditions = ['c.deleted_at IS NULL'];
        $params = [];

        if ($search !== null && $search !== '') {
            // FIX: lectures has no `name` column — it's `title`.
            $conditions[] = '(c.code LIKE ? OR l.title LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($lectureId !== null) {
            $conditions[] = 'c.lecture_id = ?';
            $params[] = $lectureId;
        }

        if ($stageId !== null) {
            $conditions[] = 's.id = ?';
            $params[] = $stageId;
        }

        // `status` (enum) takes precedence over the legacy boolean `is_active`
        // when both are supplied, so existing callers keep working unchanged.
        if ($status !== null && $status !== 'all' && $status !== '') {
            switch ($status) {
                case 'active':
                    $conditions[] = 'c.is_active = 1';
                    $conditions[] = '(c.expire_at IS NULL OR c.expire_at >= NOW())';
                    $conditions[] = 'c.times_used < c.max_uses';
                    break;
                case 'inactive':
                    $conditions[] = 'c.is_active = 0';
                    break;
                case 'expired':
                    $conditions[] = 'c.expire_at IS NOT NULL AND c.expire_at < NOW()';
                    break;
                case 'fully_used':
                    $conditions[] = 'c.times_used >= c.max_uses';
                    break;
            }
        } elseif ($isActive !== null) {
            $conditions[] = 'c.is_active = ?';
            $params[] = (int) $isActive;
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function sanitizeSortColumn(string $column): string
    {
        $allowed = CouponConstants::ALLOWED_COUPON_SORT_FIELDS;
        return in_array($column, $allowed, true) ? $column : 'created_at';
    }
} 