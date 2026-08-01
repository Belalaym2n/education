<?php

namespace App\Contracts;

interface StudentRepositoryInterface
{
    public function find(int $id): ?array;

 
    public function all(array $filters, int $limit, int $offset, string $sort, string $order): array;

    public function create(array $data): int;
 
    public function update(int $id, array $data): int;

    public function delete(int $id): int;
}  