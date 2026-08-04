<?php

namespace App\Repositories;

use App\Contracts\FatherStudentRepositoryInterface;
use App\Database\Database;

/**
 * Persistence for the `father_student` join table. This is the single
 * place that knows how to resolve "student -> guardian(s)" and
 * "guardian -> student(s)" — no other class should join these tables
 * directly, so the relationship stays swappable in one spot.
 */
class FatherStudentRepository implements FatherStudentRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function link(int $fatherId, int $studentId, string $relationshipType = 'father', bool $isPrimary = true): int
    {
        return (int) $this->db->insert('father_student', [
            'father_id' => $fatherId,
            'student_id' => $studentId,
            'relationship_type' => $relationshipType,
            'is_primary_guardian' => $isPrimary ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findFatherUserIdsByStudentId(int $studentId): array
    {
        return $this->findFatherUserIdsByStudentIds([$studentId]);
    }

    public function findFatherUserIdsByStudentIds(array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        if (empty($studentIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

        $rows = $this->db->query(
            "
            SELECT DISTINCT f.user_id
            FROM father_student fs
            INNER JOIN fathers f ON f.id = fs.father_id AND f.deleted_at IS NULL
            INNER JOIN users u ON u.id = f.user_id AND u.deleted_at IS NULL AND u.is_active = 1
            WHERE fs.student_id IN ($placeholders)
            ",
            $studentIds
        );

        return array_map(fn($row) => (int) $row['user_id'], $rows);
    }

    public function findStudentIdsWithAnyFather(array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        if (empty($studentIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

        $rows = $this->db->query(
            "
            SELECT DISTINCT fs.student_id
            FROM father_student fs
            INNER JOIN fathers f ON f.id = fs.father_id AND f.deleted_at IS NULL
            WHERE fs.student_id IN ($placeholders)
            ",
            $studentIds
        );

        return array_map(fn($row) => (int) $row['student_id'], $rows);
    }

    public function findStudentIdsByFatherUserId(int $fatherUserId): array
    {
        $rows = $this->db->query(
            "
            SELECT fs.student_id
            FROM father_student fs
            INNER JOIN fathers f ON f.id = fs.father_id
            WHERE f.user_id = ? AND f.deleted_at IS NULL
            ",
            [$fatherUserId]
        );

        return array_map(fn($row) => (int) $row['student_id'], $rows);
    }
}  