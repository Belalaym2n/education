<?php

namespace App\Contracts;

use App\DTO\NotificationFilter;

interface NotificationRepositoryInterface
{ 
    public function find(int $id, bool $includeDeleted = false): ?array;

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(NotificationFilter $filter, int $limit, int $offset): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function softDelete(int $id): int;

    public function restore(int $id): int;

    public function setActive(int $id, bool $active): int;

    /** @param int[] $ids */
    public function bulkSoftDelete(array $ids): int;

    /** @param int[] $ids */
    public function bulkRestore(array $ids): int;

    /** @param int[] $ids */
    public function bulkSetActive(array $ids, bool $active): int;

    public function markSent(int $id): int;

    /**
     * Resolve the list of user IDs who should receive this notification,
     * based on its target_type/stage_id. Only active, non-deleted students.
     *
     * @return int[] user_id list
     */
    public function resolveRecipientUserIds(array $notification): array;
}