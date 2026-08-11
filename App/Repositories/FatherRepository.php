<?php

namespace App\Repositories;

use App\Contracts\FatherRepositoryInterface;
use App\Database\Database; 

/**
 * Persistence for the `fathers` table (1:1 extension of a `users` row,
 * exactly like `students` extends `users`).
 */
class FatherRepository implements FatherRepositoryInterface
{ 
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    } 

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM fathers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    public function findByUserId(int $userId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM fathers WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        );
    }

    public function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return (int) $this->db->insert('fathers', $data);
    } 

    public function delete(int $id): int
    {
        return $this->db->update(
            'fathers',
            [
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$id]
        );
    } 
} 