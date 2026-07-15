<?php

namespace App\Contracts;

interface UserRepositoryInterface
{
    public function find(int $id): ?array;

    public function findByEmail(string $email): 
    ?array;

    public function all(int $limit = 50, int $offset = 0): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function delete(int $id): int;

    public function exists(string $email): bool;

    public function updatePassword(int $id, string $hashedPassword): int;

    public function updateRole(int $id, string $role): int;

    public function setActive(int $id, bool $isActive): int;
}