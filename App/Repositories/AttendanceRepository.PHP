<?php

namespace App\Repositories;

use App\Contracts\AttendanceRepositoryInterface;
use App\Database\Database;

/**
 * Persistence for `attendance_records`. create() is the original write
 * path (unchanged, still used by AttendanceService). Everything else here
 * is new: read-side support for the Attendance Records API, Statistics,
 * and the Parent Notification endpoint (Task 3).
 */
class AttendanceRepository implements AttendanceRepositoryInterface
{
    private const TABLE = 'attendance_records';

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $data['recorded_at'] = $data['recorded_at'] ?? date('Y-m-d H:i:s');
        $data['created_at'] = date('Y-m-d H:i:s');

        return (int) $this->db->insert(self::TABLE, $data);
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "
            SELECT
                ar.id, ar.student_id, ar.status, ar.message, ar.notification_id,
                ar.recorded_by, ar.recorded_at, ar.created_at,
                s.qr_code, su.full_name AS student_name
            FROM attendance_records ar
            INNER JOIN students s ON s.id = ar.student_id
            INNER JOIN users su ON su.id = s.user_id
            WHERE ar.id = ?
            ",
            [$id]
        );
    }

    public function paginate(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        $sortable = ['recorded_at', 'created_at', 'status', 'student_name'];
        $sortColumn = in_array($sort, $sortable, true) ? $sort : 'recorded_at';
        // student_name is an alias, not a real column — map it explicitly.
        $sortSql = $sortColumn === 'student_name' ? 'su.full_name' : "ar.$sortColumn";
        $orderSql = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        [$where, $params] = $this->buildWhere($filters);
        $whereSql = implode(' AND ', $where);

        $total = $this->db->selectOne(
            "
            SELECT COUNT(*) AS total
            FROM attendance_records ar
            INNER JOIN students s ON s.id = ar.student_id
            INNER JOIN users su ON su.id = s.user_id
            LEFT JOIN stages st ON st.id = s.stage_id
            WHERE $whereSql
            ",
            $params
        );

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $rows = $this->db->query(
            "
            SELECT
                ar.id,
                ar.status,
                ar.message,
                ar.notification_id,
                ar.recorded_at,
                ar.created_at,

                s.id AS student_id,
                s.qr_code,
                s.stage_id,
                st.name AS stage_name,
                su.full_name AS student_name,

                ru.full_name AS created_by_name,

                (
                    SELECT GROUP_CONCAT(fu.full_name SEPARATOR ', ')
                    FROM father_student fs
                    INNER JOIN fathers f ON f.id = fs.father_id
                    INNER JOIN users fu ON fu.id = f.user_id
                    WHERE fs.student_id = s.id
                ) AS father_names

            FROM attendance_records ar
            INNER JOIN students s ON s.id = ar.student_id
            INNER JOIN users su ON su.id = s.user_id
            LEFT JOIN stages st ON st.id = s.stage_id
            LEFT JOIN users ru ON ru.id = ar.recorded_by

            WHERE $whereSql

            ORDER BY $sortSql $orderSql

            LIMIT $limit OFFSET $offset
            ",
            $params
        );

        return [
            'data' => $rows,
            'total' => (int) ($total['total'] ?? 0),
        ];
    }

    public function statistics(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $whereSql = implode(' AND ', $where);

        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $monthStart = date('Y-m-01');

        $row = $this->db->selectOne(
            "
            SELECT
                COUNT(*) AS total_attendance,
                SUM(CASE WHEN DATE(ar.recorded_at) = ? THEN 1 ELSE 0 END) AS present_today,
                SUM(CASE WHEN ar.recorded_at >= ? THEN 1 ELSE 0 END) AS present_this_week,
                SUM(CASE WHEN ar.recorded_at >= ? THEN 1 ELSE 0 END) AS present_this_month,
                SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS status_present_count,
                SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS status_absent_count,
                SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) AS status_late_count,
                COUNT(DISTINCT ar.student_id) AS students_scanned
            FROM attendance_records ar
            INNER JOIN students s ON s.id = ar.student_id
            INNER JOIN users su ON su.id = s.user_id
            LEFT JOIN stages st ON st.id = s.stage_id
            WHERE $whereSql
            ",
            array_merge([$today, $weekStart, $monthStart], $params)
        );

        $total = (int) ($row['total_attendance'] ?? 0);
        $presentCount = (int) ($row['status_present_count'] ?? 0);
        $percentage = $total > 0 ? round(($presentCount / $total) * 100, 2) : 0.0;

        return [
            'total_attendance' => $total,
            'present_today' => (int) ($row['present_today'] ?? 0),
            'present_this_week' => (int) ($row['present_this_week'] ?? 0),
            'present_this_month' => (int) ($row['present_this_month'] ?? 0),
            'attendance_percentage' => $percentage,
            'students_scanned' => (int) ($row['students_scanned'] ?? 0),
            'status_breakdown' => [
                'present' => $presentCount,
                'absent' => (int) ($row['status_absent_count'] ?? 0),
                'late' => (int) ($row['status_late_count'] ?? 0),
            ],
            // These two cannot be derived server-side: a "failed scan" that
            // never reached the API leaves no row here, and "pending
            // offline sync" only exists in each device's local queue. The
            // Flutter dashboard should merge its own queue count in on top
            // of this response rather than expect it from the server.
            'failed_scans' => null,
            'pending_offline_sync' => null,
        ];
    }

    public function attachNotification(int $attendanceId, int $notificationId): int
    {
        return $this->db->update(
            self::TABLE,
            ['notification_id' => $notificationId],
            'id = ?',
            [$attendanceId]
        );
    }

    /**
     * @return array{0: string[], 1: array<int, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['date'])) {
            $where[] = 'DATE(ar.recorded_at) = ?';
            $params[] = $filters['date'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'ar.recorded_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'ar.recorded_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['stage_id'])) {
            $where[] = 's.stage_id = ?';
            $params[] = (int) $filters['stage_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'ar.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['qr_code'])) {
            $where[] = 's.qr_code = ?';
            $params[] = $filters['qr_code'];
        }

        if (!empty($filters['student_name'])) {
            $where[] = 'su.full_name LIKE ?';
            $params[] = '%' . $filters['student_name'] . '%';
        }

        if (!empty($filters['father_name'])) {
            $where[] = '
                EXISTS (
                    SELECT 1 FROM father_student fs2
                    INNER JOIN fathers f2 ON f2.id = fs2.father_id
                    INNER JOIN users fu2 ON fu2.id = f2.user_id
                    WHERE fs2.student_id = s.id AND fu2.full_name LIKE ?
                )
            ';
            $params[] = '%' . $filters['father_name'] . '%';
        }

        // Free-text search across student name, father name, and QR code —
        // used by the Dashboard's single search box.
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $where[] = '
                (
                    su.full_name LIKE ?
                    OR s.qr_code LIKE ?
                    OR EXISTS (
                        SELECT 1 FROM father_student fs3
                        INNER JOIN fathers f3 ON f3.id = fs3.father_id
                        INNER JOIN users fu3 ON fu3.id = f3.user_id
                        WHERE fs3.student_id = s.id AND fu3.full_name LIKE ?
                    )
                )
            ';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        } 

        if (empty($where)) {
            $where[] = '1 = 1';
        }

        return [$where, $params];
    }
}