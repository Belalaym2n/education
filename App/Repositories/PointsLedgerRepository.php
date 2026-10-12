<?php

namespace App\Repositories;

use App\Contracts\PointsLedgerRepositoryInterface;
use App\Database\Database;
use PDOException;

class PointsLedgerRepository implements PointsLedgerRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function add(int $studentId, int $stageId, int $points, string $sourceType, int $sourceId, ?int $examId, string $createdAtUtc): bool
    {
        try {
            $this->db->insert('student_points_ledger', [
                'student_id' => $studentId,
                'stage_id' => $stageId,
                'points' => $points,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'exam_id' => $examId,
                'created_at' => $createdAtUtc,
            ]);
            return true;
        } catch (PDOException $e) {
            // 23000 = integrity constraint (uq_ledger_source): already counted.
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function applyToStudent(int $studentId, int $delta, ?int $floor): int
    {
        if ($floor === null) {
            return $this->db->execute("UPDATE students SET points = points + ? WHERE id = ?", [$delta, $studentId]);
        }
        return $this->db->execute(
            "UPDATE students SET points = GREATEST(?, points + ?) WHERE id = ?",
            [$floor, $delta, $studentId]
        );
    }

    public function studentPoints(int $studentId): int
    {
        $row = $this->db->selectOne("SELECT points FROM students WHERE id = ?", [$studentId]);
        return (int) ($row['points'] ?? 0);
    }

    public function drift(): array
    {
        return $this->db->query(
            "SELECT s.id AS student_id, s.points AS stored_points, COALESCE(l.total, 0) AS ledger_points
             FROM students s
             LEFT JOIN (SELECT student_id, SUM(points) AS total FROM student_points_ledger GROUP BY student_id) l
               ON l.student_id = s.id
             WHERE s.points <> COALESCE(l.total, 0)"
        );
    }

    public function recalculate(int $studentId): int
    {
        return $this->db->execute(
            "UPDATE students s
             SET s.points = (SELECT COALESCE(SUM(points), 0) FROM student_points_ledger WHERE student_id = s.id)
             WHERE s.id = ?",
            [$studentId]
        );
    }
}
