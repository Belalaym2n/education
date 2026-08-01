<?php

namespace App\Repositories;

use App\Contracts\StudentRepositoryInterface;
use App\Database\Database;

class StudentRepository implements StudentRepositoryInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,

            u.role_id,
            r.code AS role_name,

            s.id AS student_id,
            s.stage_id, 
            s.average_score,
            s.total_quizzes,
            s.total_degrees,
            st.name AS stage_name,

            u.is_active,
            u.created_at, 
            u.updated_at

        FROM users u

        INNER JOIN roles r
            ON r.id = u.role_id

        LEFT JOIN students s
            ON s.user_id = u.id

        LEFT JOIN stages st
            ON st.id = s.stage_id

        WHERE u.id = ?
        ",
            [$id]

        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM students WHERE email = ?",
            [$email]
        );
    }

  public function all(array $filters, int $limit, int $offset, string $sort, string $order): array
{


    $sortable = [
        'id',
        'full_name',
        'email',
        'created_at',
        'average_score',
    ]; 

    $sort = in_array($sort, $sortable, true) ? $sort : 'created_at';
    $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

    $where = ['s.deleted_at IS NULL'];
    $params = [];

    if (isset($filters['stage_id'])) {
        $where[] = 's.stage_id = ?';
        $params[] = (int)$filters['stage_id'];
    }

    if (isset($filters['is_active'])) {
        $where[] = 'u.is_active = ?';
        $params[] = (int)$filters['is_active'];
    }

    if (!empty($filters['search'])) {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }

    $whereSql = implode(' AND ', $where);
 
    $total = $this->db->selectOne(
        "
        SELECT COUNT(*) AS total
        FROM students s
        INNER JOIN users u ON u.id = s.user_id
        INNER JOIN roles r ON r.id = u.role_id
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
            s.id,
            s.user_id,
            s.stage_id,
            s.parent_phone,
            s.profile_image,
            s.enrollment_date,
            s.average_score,
            s.total_quizzes,
            s.total_degrees,
            s.total_lectures,
            s.created_at,
            s.updated_at,
            s.deleted_at,

            u.full_name,
            u.email,
            u.phone,
            u.is_active,

            r.id AS role_id,
            r.code AS role_name,

            st.name AS stage_name

        FROM students s
        INNER JOIN users u ON u.id = s.user_id
        INNER JOIN roles r ON r.id = u.role_id
        LEFT JOIN stages st ON st.id = s.stage_id

        WHERE $whereSql

        ORDER BY $sort $order

        LIMIT $limit OFFSET $offset
        ",
        $params
    );
 
    return [ 
        'data' => $rows,
        'total' => (int)($total['total'] ?? 0),
    ];
} 

    public function create(array $data): int
    {

        return (int) $this->db->insert('students', $data);
    }

    public function update(int $id, array $data): int
    {

return $this->db->update(
    'students',
    $data,
    'id = ?',
    [$id]
);    }
  
 public function delete(int $id): int
{
    return $this->db->update(
        'students',
        [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ],
        'id = ?',
        [$id]
    );
} 
}
 