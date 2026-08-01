<?php

namespace App\Controllers;

use App\Services\NotificationService;

/**
 * Mobile-app-facing controller. Every action here is scoped to the
 * authenticated user (from AuthMiddleware's $_SERVER['auth_user_id']).
 */
final class FlutterNotificationController
{
    private   NotificationService $notificationService;
    public function __construct(    NotificationService $notificationService)
    {
        $this->notificationService=$notificationService;
    }

    public function index(): array
    {
        $userId = (int) $_SERVER['auth_user_id'];
        $unreadOnly = isset($_GET['unread_only']) && (int) $_GET['unread_only'] === 1;
        $limit = (int) ($_GET['limit'] ?? 20);
        $offset = (int) ($_GET['offset'] ?? 0);

        return ['success' => true, 'data' => $this->notificationService->listForUser($userId, $unreadOnly, $limit, $offset)];
    }

     public function unreadCount(): array
    {
        $userId = (int) $_SERVER['auth_user_id'];
        return ['success' => true, 'data' => ['unread_count' => $this->notificationService->unreadCount($userId)]];
    }

    public function markAsRead(int $notificationId, array $requestData): array
    {
        $userId = (int) $_SERVER['auth_user_id'];
        $deviceId = isset($requestData['device_id']) ? (int) $requestData['device_id'] : null;

        $this->notificationService->markAsRead($notificationId, $userId, $deviceId);

        return ['success' => true, 'message' => 'Marked as read'];
    }

    public function markAllAsRead(): array
    {
        $userId = (int) $_SERVER['auth_user_id'];
        $count = $this->notificationService->markAllAsRead($userId);

        return ['success' => true, 'affected' => $count];
    }

    public function show(int $notificationId): array
    {
        return ['success' => true, 'data' => $this->notificationService->getNotification($notificationId)];
    }

    public function destroyLocal(int $notificationId): array
    {
        $userId = (int) $_SERVER['auth_user_id'];
        $this->notificationService->deleteLocally($notificationId, $userId);

        return ['success' => true, 'message' => 'Removed from your device list'];
    }
}