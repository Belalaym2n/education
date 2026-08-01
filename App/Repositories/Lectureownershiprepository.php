<?php

namespace App\Repositories;

use App\Contracts\LectureOwnershipRepositoryInterface;
use App\Constants\CouponConstants;
use App\Database\Database;

class LectureOwnershipRepository implements LectureOwnershipRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    } 

    public function find(int $id): ?array
    { 
        return $this->db->selectOne(
            "SELECT * FROM lecture_ownerships WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    } 

    public function findByStudentAndLecture(int $studentId, int $lectureId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM lecture_ownerships
             WHERE student_id = ? AND lecture_id = ? AND deleted_at IS NULL",
            [$studentId, $lectureId]
        );
    }

    public function findByStudentAndLectureForUpdate(int $studentId, int $lectureId): ?array
    {
        // Locks the (student_id, lecture_id) unique-key row — or, if it
        // doesn't exist yet, this SELECT ... FOR UPDATE still takes a
        // gap/next-key lock under InnoDB REPEATABLE READ, which is what
        // actually prevents two concurrent redemptions from both
        // inserting the same ownership row. Combined with the coupon
        // row lock in CouponService, this closes the race window.
        return $this->db->selectOne(
            "SELECT * FROM lecture_ownerships
             WHERE student_id = ? AND lecture_id = ? AND deleted_at IS NULL
             FOR UPDATE",
            [$studentId, $lectureId]
        );
    }

    public function ownsLecture(int $studentId, int $lectureId): bool
    {
        return $this->findByStudentAndLecture($studentId, $lectureId) !== null;
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('lecture_ownerships', $data);
    }

    public function paginate(
        int $studentId,
        int $limit,
        int $offset,
        ?string $search = null,
        ?int $stageId = null,
        string $sortBy = 'purchased_at',
        string $sortDir = 'DESC'
    ): array {
        [$where, $params] = $this->buildFilters($studentId, $search, $stageId);

        $sortBy = $this->sanitizeSortColumn($sortBy);
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, min($limit, CouponConstants::MAX_PAGE_SIZE));
        $offset = max(0, $offset);

        // Sorting by "price" or "name" needs the lectures table, so those
        // columns are aliased explicitly and referenced unambiguously.
        $orderColumn = $sortBy === 'purchased_at' ? 'o.purchased_at' : ($sortBy === 'price' ? 'l.price' : 'l.name');

        $sql = "SELECT
                    o.id AS ownership_id,
                    o.purchased_at,
                    o.purchase_price,
                    l.id AS lecture_id,
                    l.title,
                    l.description,
                    l.price,
                    l.youtube_url,
                    l.stage_id,
                    st.name AS stage_name
                FROM lecture_ownerships o
                INNER JOIN lectures l ON l.id = o.lecture_id AND l.deleted_at IS NULL
                LEFT JOIN stages st ON st.id = l.stage_id
                WHERE $where
                ORDER BY $orderColumn $sortDir
                LIMIT $limit OFFSET $offset";

        return $this->db->query($sql, $params);
    }

    public function count(int $studentId, ?string $search = null, ?int $stageId = null): int
    {
        [$where, $params] = $this->buildFilters($studentId, $search, $stageId);

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total
             FROM lecture_ownerships o
             INNER JOIN lectures l ON l.id = o.lecture_id AND l.deleted_at IS NULL
             WHERE $where",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function buildFilters(int $studentId, ?string $search, ?int $stageId): array
    {
        $conditions = ['o.deleted_at IS NULL', 'o.student_id = ?'];
        $params = [$studentId];

        if ($search !== null && $search !== '') {
            $conditions[] = 'l.name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        if ($stageId !== null) {
            $conditions[] = 'l.stage_id = ?';
            $params[] = $stageId;
        }

        return [implode(' AND ', $conditions), $params];
    }
  
    private function sanitizeSortColumn(string $column): string
    {
        $allowed = CouponConstants::ALLOWED_MY_LECTURES_SORT_FIELDS;
        return in_array($column, $allowed, true) ? $column : 'purchased_at';
    }
}