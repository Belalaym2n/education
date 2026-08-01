<?php

namespace App\Repositories;

use App\Contracts\StudentResultRepositoryInterface;
use App\Database\Database;

class StudentResultRepository implements StudentResultRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Detail view: joined with student/user/stage/academic_year names.
     */
public function find(int $id): ?array
{
    return $this->db->selectOne(
        "SELECT
            sr.id,
            sr.student_name,
            sr.seat_number,

            sr.academic_year_id,
            ay.label AS academic_year_label,

            sr.stage_id,
            st.name AS stage_name,

            sr.headline,
            sr.description,
            sr.student_message,
            sr.image_url,

            sr.total_score,
            sr.percentage,
            sr.grade,
            sr.rank,
            sr.is_published,

            sr.created_by,
            u_creator.full_name AS created_by_name,

            sr.updated_by,
            u_updater.full_name AS updated_by_name,

            sr.created_at,
            sr.updated_at

        FROM student_results sr

        INNER JOIN academic_years ay
            ON ay.id = sr.academic_year_id

        INNER JOIN stages st
            ON st.id = sr.stage_id

        INNER JOIN users u_creator
            ON u_creator.id = sr.created_by

        LEFT JOIN users u_updater
            ON u_updater.id = sr.updated_by

        WHERE sr.id = ?
        AND sr.deleted_at IS NULL",
        [$id]
    );
} 

    /**
     * Raw row (no joins) — used internally by the service for update/delete
     * business-rule checks without paying for join cost.
     */
    public function findRaw(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM student_results WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    public function existsForStudentInYear(int $studentId, int $academicYearId, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM student_results
                WHERE student_id = ? AND academic_year_id = ? AND deleted_at IS NULL";
        $params = [$studentId, $academicYearId];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $sql .= " LIMIT 1";

        return $this->db->selectOne($sql, $params) !== null;
    }

public function paginate(
    array $filters,
    int $limit,
    int $offset,
    string $sortBy,
    string $sortDir
): array {
    [$where, $params] = $this->buildWhere($filters);

    $allowedSort = [
        'created_at',
        'updated_at',
        'headline',
        'student_name',
        'seat_number',
        'total_score',
        'percentage',
        'rank',
        'id'
    ];

    if (!in_array($sortBy, $allowedSort, true)) {
        $sortBy = 'created_at';
    }

    $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

    $limit = (int) $limit;
    $offset = (int) $offset;

    $sql = "
        SELECT
            sr.id,
            sr.student_name,
            sr.seat_number,
            sr.student_message,

            sr.academic_year_id,
            ay.label AS academic_year_label,

            sr.stage_id,
            st.name AS stage_name,

            sr.headline,
            sr.image_url,

            sr.total_score,
            sr.percentage,
            sr.grade,
            sr.rank,
            sr.is_published,

            sr.created_by,
            u_creator.full_name AS created_by_name,

            sr.created_at,
            sr.updated_at

        FROM student_results sr

        INNER JOIN academic_years ay
            ON ay.id = sr.academic_year_id

        INNER JOIN stages st
            ON st.id = sr.stage_id

        INNER JOIN users u_creator
            ON u_creator.id = sr.created_by

        WHERE $where

        ORDER BY sr.$sortBy $sortDir

        LIMIT $limit OFFSET $offset
    ";

    return $this->db->query($sql, $params);
} 

    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total FROM student_results sr WHERE $where",
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    public function create(array $data): int
    {
        return (int) $this->db->insert('student_results', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('student_results', $data, 'id = ?', [$id]);
    }

    public function softDelete(int $id): int
    {
        return $this->db->update(
            'student_results',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
    }

    /**
     * Builds a safe, parameterized WHERE clause from filters.
     *
     * Supported filter keys:
     *  - academic_year_id (int)
     *  - student_id (int)
     *  - stage_id (int)
     *  - teacher_id / created_by (int)
     *  - date_from, date_to        -> filters created_at (result "published" date)
     *  - updated_from, updated_to  -> filters updated_at
     *  - keyword                   -> FULLTEXT search on headline/description/student_message
     *
     * @return array{0: string, 1: array}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = ['sr.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['academic_year_id'])) {
            $conditions[] = 'sr.academic_year_id = ?';
            $params[] = (int) $filters['academic_year_id'];
        }

        if (!empty($filters['student_id'])) {
            $conditions[] = 'sr.student_id = ?';
            $params[] = (int) $filters['student_id'];
        }

        if (!empty($filters['stage_id'])) {
            $conditions[] = 'sr.stage_id = ?';
            $params[] = (int) $filters['stage_id'];
        }

        if (!empty($filters['teacher_id'])) {
            $conditions[] = 'sr.created_by = ?';
            $params[] = (int) $filters['teacher_id'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'sr.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'sr.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['updated_from'])) {
            $conditions[] = 'sr.updated_at >= ?';
            $params[] = $filters['updated_from'] . ' 00:00:00';
        }

        if (!empty($filters['updated_to'])) {
            $conditions[] = 'sr.updated_at <= ?';
            $params[] = $filters['updated_to'] . ' 23:59:59';
        }

        if (!empty($filters['keyword'])) {
            // Natural language FULLTEXT search - far more efficient than
            // three separate LIKE '%keyword%' scans across text columns.
            $conditions[] = 'MATCH(sr.headline, sr.description, sr.student_message) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $filters['keyword'];
        }

        return [implode(' AND ', $conditions), $params];
    }
}
