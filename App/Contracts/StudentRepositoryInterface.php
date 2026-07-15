<?php

namespace App\Contracts;

interface StudentRepositoryInterface
{
    public function find(int $id): ?array;

 
    public function all(int $limit = 50, int $offset = 0): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function delete(int $id): int;
} 