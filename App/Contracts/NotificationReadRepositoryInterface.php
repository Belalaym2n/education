<?php

namespace App\Contracts;

interface NotificationReadRepositoryInterface
{
    /** @param int[] $userIds */
    public function bulkCreatePending(int $notificationId, array $userIds): void;

    public function markAsRead(int $notificationId, int $userId, ?int $deviceId = null): int;

    public function markAllAsRead(int $userId): int;
     public function unreadCount(int $userId): int;

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listForUser(int $userId, bool $unreadOnly, int $limit, int $offset): array;

    public function softDeleteLocally(int $notificationId, int $userId): int;
}