<?php

namespace App\Contracts;

interface FatherRepositoryInterface
{
    public function find(int $id): ?array;

    public function findByUserId(int $userId): ?array;

    public function create(array $data): int;

    public function delete(int $id): int;
}        