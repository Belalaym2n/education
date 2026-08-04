<?php

namespace App\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Database\Database;

class UserRepository implements UserRepositoryInterface
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
            u.password_hash,
            u.role_id, 
            r.code AS role_name,

            s.id AS student_id,
            s.stage_id,
            s.parent_phone,
            s.profile_image,
            s.enrollment_date,
            s.average_score,
            s.total_quizzes,
            s.total_degrees,
            s.total_lectures,
            s.created_at AS student_created_at,
            s.updated_at AS student_updated_at,

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
            "  
         SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,

            u.password_hash,          -- مهم جدًا

            u.role_id,
            r.code AS role_name,

            s.id AS student_id,
            s.stage_id,
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

        WHERE u.email = ?
        ",
            [$email]
        );
    }

    public function all(int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;

        return $this->db->query(
            "SELECT id, name, email, role, is_active, created_at, updated_at
             FROM users ORDER BY id DESC LIMIT $limit OFFSET $offset"
        );
    }

    public function create(array $data): int
    {
        $data['role_id'] = $data['role_id'] ?? 1;
        $data['is_active'] = $data['is_active'] ?? 1;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return (int) $this->db->insert('users', $data);
    }
    public function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->db->update(
            'users',
            $data,
            'id = ?',
            [$id]
        );



        // var_dump($user);
        // die();

        return $affected;
    }

 public function delete(int $id): int 
{
    return $this->db->update(
        'users',
        [
            'is_active' => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ],
        'id = ?',
        [$id]
    );
}

    public function exists(string $email): bool
    {
        $user = $this->findByEmail($email);
        return $user !== null;
    }

    public function updatePassword(int $id, string $hashedPassword): int
    {
        return $this->db->update('users', [
            'password' => $hashedPassword, 
            'updated_at' => date('Y-m-d H:i:s')
        ], $id);
    }
 
    public function updateRole(int $id, string $role): int
    {
        return $this->db->update('users', [
            'role' => $role,
            'updated_at' => date('Y-m-d H:i:s')
        ], $id);
    }

    public function setActive(int $id, bool $isActive): int
    {
        return $this->db->update('users', [
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ], $id);
    }
}
