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

    public function all(int $limit = 50, int $offset = 0): array
    {
        $limit = (int)$limit;
        $offset = (int)$offset;

        return $this->db->query(
             " SELECT
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

            r.id   AS role_id,
            r.code AS role_name,

            st.name AS stage_name

        FROM students s

        INNER JOIN users u
            ON u.id = s.user_id

        INNER JOIN roles r
            ON r.id = u.role_id

        LEFT JOIN stages st
            ON st.id = s.stage_id

        WHERE s.deleted_at IS NULL

        ORDER BY s.id DESC
        LIMIT $limit OFFSET $offset"
        );
    }

    public function create(array $data)
    : int
    {

        return (int) $this->db->
        insert('students', $data);
    }

    public function update(int $id, array $data): int
    {

        return $this->db->update('students', $data, 'id = ?', [$id]);
    }

    public function delete(int $id): int
    {

        return $this->db->delete('students', 'id = ?', [$id]);
    }
}
