<?php

namespace App\Services;

use App\Contracts\DeviceRepositoryInterface;
use App\Contracts\NotificationReadRepositoryInterface;
use App\Contracts\NotificationRepositoryInterface;
use App\Contracts\StageRepositoryInterface;
use App\Contracts\StudentRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\DTO\NotificationFilter;
use App\DTO\PaginationResult;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Owns all notification business rules: creation, targeting resolution,
 * dispatch orchestration (delegating actual push delivery to FirebaseService),
 * and lifecycle (enable/disable/soft-delete/restore/bulk actions).
 *
 * Controllers and other services (e.g. lecture creation, attendance scanning,
 * the admin "notify parents" dashboard action) all call this single entry
 * point — there is exactly one place that knows how to "create + target + send"
 * a notification, including parent-targeted ones.
 */
final class NotificationService
{
    private NotificationRepositoryInterface $notificationRepo;
    private StageRepositoryInterface $stageInterFace;
    private StudentRepositoryInterface $student;
    private NotificationReadRepositoryInterface $readRepo;
    private DeviceRepositoryInterface $deviceRepo;
    private FirebaseService $firebase;
    private Database $db;
    private Logger $logger;

    public function __construct(
        NotificationRepositoryInterface $notificationRepo,
        NotificationReadRepositoryInterface $readRepo,
        DeviceRepositoryInterface $deviceRepo,
        StudentRepositoryInterface $student,

        FirebaseService $firebase,
        Database $db,
        Logger $logger,
        StageRepositoryInterface $stageInterFace
    ) {
        $this->notificationRepo = $notificationRepo;
        $this->readRepo = $readRepo;
        $this->deviceRepo = $deviceRepo;
        $this->firebase = $firebase;
        $this->db = $db;
        $this->student = $student;
        $this->stageInterFace = $stageInterFace;

        $this->logger = $logger;
    }


    /**
     * Creates a notification from validated admin input and, if `send_now`
     * is true (or a scheduled time has already passed), dispatches it.
     */
    public function createNotification(array $data, int $createdBy): array
    {
        $this->assertValidTargeting($data);
 
        $data['created_by'] = $createdBy;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['send_now'] = $data['send_now'] ?? true;
        $data['notification_type'] = $data['notification_type'] ?? NotificationType::SYSTEM;

$startedTransaction = false;

if (!$this->db->inTransaction()) {
    $this->db->beginTransaction();
    $startedTransaction = true;
}


        try {
    $id = $this->notificationRepo->create($data);

if ($startedTransaction) {
    $this->db->commit();
}
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Notification creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        $this->logger->info('Notification created', ['id' => $id, 'created_by' => $createdBy]);

        $notification = $this->getNotification($id);

        if ($notification['send_now']) {
            $this->dispatch($notification);
        }

        return $this->getNotification($id);
    }

    /**
     * Reusable entry point for automatic notifications triggered by other
     * domain events (currently: lecture creation). Keeps the "create +
     * target + send" logic in exactly one place, per your requirement.
     */
    public function notifyLectureCreated(array $lecture, int $createdBy): array
    {
        return $this->createNotification([
            'headline' => 'New lecture available: ' . $lecture['title'],
            'body' => $lecture['description'] ?? 'A new lecture has just been published.',
            'target_type' => NotificationTargetType::STAGE,
            'stage_id' => $lecture['stage_id'],
            'notification_type' => NotificationType::LECTURE,
            'deep_link' => 'app://lecture/' . $lecture['id'],
            'button_text' => 'Watch now',
            'source_type' => 'lecture',
            'source_id' => $lecture['id'],
            'send_now' => true,
        ], $createdBy);
    }

    /**
     * Reusable entry point for the attendance flow (single scan and each
     * batched group of a bulk scan). Never a student recipient — parents only.
     *
     * @param int[] $studentIds one or more students whose guardian(s) should be notified
     */
    public function notifyParentsOfStudents(array $studentIds, string $headline, string $body, int $createdBy): array
    {
        return $this->createNotification([
            'headline' => $headline,
            'body' => $body,
            'target_type' => NotificationTargetType::PARENT,
            'target_student_ids' => array_values(array_unique(array_map('intval', $studentIds))),
            'notification_type' => NotificationType::ATTENDANCE,
            'source_type' => 'attendance',
            'send_now' => true,
        ], $createdBy);
    }

    /**
     * Admin dashboard: broadcast to every parent/guardian account.
     */
    public function notifyAllParents(string $headline, string $body, int $createdBy): array
    {
        return $this->createNotification([
            'headline' => $headline,
            'body' => $body,
            'target_type' => NotificationTargetType::PARENT,
            'notification_type' => NotificationType::SYSTEM,
            'send_now' => true,
        ], $createdBy);
    }

    public function getNotification(int $id): array
    {
        $notification = $this->notificationRepo->find($id);

        if ($notification === null) {
            throw new NotFoundException('Notification');
        }

        return $notification;
    }

    public function listNotifications(array $query): array
    {
        $filter = NotificationFilter::fromArray($query);
        $limit = min((int) ($query['limit'] ?? 20), 100);
        $offset = max((int) ($query['offset'] ?? 0), 0);

        $result = $this->notificationRepo->paginate($filter, $limit, $offset);

        return (new PaginationResult($result['items'], $result['total'], $limit, $offset))->toArray();
    }

    public function updateNotification(int $id, array $data, int $updatedBy): array
    {
        $this->getNotification($id); // ensures it exists

        if (!empty($data)) {
            if (isset($data['target_type'])) {
                $this->assertValidTargeting($data);
            }

            $data['updated_by'] = $updatedBy;

            $this->notificationRepo->update($id, $data);
        }

        $this->logger->info('Notification updated', ['id' => $id, 'updated_by' => $updatedBy]);

        return $this->getNotification($id);
    }

    public function softDelete(int $id): void
    {
        $this->getNotification($id);
        $this->notificationRepo->softDelete($id);
        $this->logger->info('Notification soft-deleted', ['id' => $id]);
    }

    public function restore(int $id): array
    {
        $this->notificationRepo->find($id, includeDeleted: true) ?? throw new NotFoundException('Notification');
        $this->notificationRepo->restore($id);
        $this->logger->info('Notification restored', ['id' => $id]);

        return $this->getNotification($id);
    }

    public function enable(int $id): array
    {
        $this->getNotification($id);
        $this->notificationRepo->setActive($id, true);

        return $this->getNotification($id);
    }

    public function disable(int $id): array
    {
        $this->getNotification($id);
        $this->notificationRepo->setActive($id, false);

        return $this->getNotification($id);
    }

    /** @param int[] $ids */
    public function bulkDelete(array $ids): int
    {
        $count = $this->notificationRepo->bulkSoftDelete($ids);
        $this->logger->info('Bulk notification delete', ['ids' => $ids]);

        return $count;
    }

    /** @param int[] $ids */
    public function bulkRestore(array $ids): int
    {
        return $this->notificationRepo->bulkRestore($ids);
    }

    /** @param int[] $ids */
    public function bulkEnable(array $ids): int
    {
        return $this->notificationRepo->bulkSetActive($ids, true);
    }

    /** @param int[] $ids */
    public function bulkDisable(array $ids): int
    {
        return $this->notificationRepo->bulkSetActive($ids, false);
    }

    /** @param int[] $ids */
    public function bulkSend(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            try {
                $notification = $this->getNotification((int) $id);
                $results[$id] = $this->dispatch($notification);
            } catch (\Throwable $e) {
                $this->logger->error('Bulk send failed for one notification', ['id' => $id, 'error' => $e->getMessage()]);
                $results[$id] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // ---------------------- Flutter-facing read APIs ----------------------

    public function listForUser(int $userId, bool $unreadOnly, int $limit, int $offset): array
    {
        $limit = min($limit, 100);
        $result = $this->readRepo->listForUser($userId, $unreadOnly, $limit, $offset);

        return (new PaginationResult($result['items'], $result['total'], $limit, $offset))->toArray();
    }

    public function markAsRead(int $notificationId, int $userId, ?int $deviceId = null): void
    {
        $this->readRepo->markAsRead($notificationId, $userId, $deviceId);
    }

    public function markAllAsRead(int $userId): int
    {
        return $this->readRepo->markAllAsRead($userId);
    }

    public function unreadCount(int $userId): int
    {
        return $this->readRepo->unreadCount($userId);
    }

    public function deleteLocally(int $notificationId, int $userId): void
    {
        $this->readRepo->softDeleteLocally($notificationId, $userId);
    }

    // ---------------------- Internal dispatch orchestration ----------------------

    /**
     * Resolves recipients, creates pending read-rows, fans out via Firebase,
     * and marks the notification as sent. Never throws to the caller for
     * per-device failures — those are logged and reflected in the result.
     */
    private function dispatch(array $notification): array
    {
        $userIds = $this->notificationRepo->resolveRecipientUserIds($notification);

        if (empty($userIds)) {
            $this->logger->warning('Notification has no recipients', ['id' => $notification['id']]);
            $this->notificationRepo->markSent((int) $notification['id']);
            return ['success' => true, 'recipients' => 0, 'devices_notified' => 0];
        }

        $this->readRepo->bulkCreatePending((int) $notification['id'], $userIds);

        $devices = $this->deviceRepo->findActiveTokensForUsers($userIds);
        $tokens = array_column($devices, 'fcm_token');

        $sendResults = [];
        if (!empty($tokens)) {
            $sendResults = $this->firebase->sendToTokens(
                $tokens,
                $notification['headline'] ?? $notification['title'],
                $notification['body'],
                ['notification_id' => (string) $notification['id'], 'deep_link' => (string) ($notification['deep_link'] ?? '')]
            );

            foreach ($sendResults as $result) {
                if (($result['invalid_token'] ?? false) === true) {
                    $this->deviceRepo->deactivateByToken($result['token']);
                }
            }
        }

        $this->notificationRepo->markSent((int) $notification['id']);

        $successCount = count(array_filter($sendResults, fn($r) => $r['success']));

        $this->logger->info('Notification dispatched', [
            'id' => $notification['id'],
            'recipients' => count($userIds),
            'devices_notified' => $successCount,
        ]);

        return [
            'success' => true,
            'recipients' => count($userIds),
            'devices_notified' => $successCount,
        ];
    }

    private function assertValidTargeting(array $data): void
    {
        $targetType = NotificationTargetType::fromString($data['target_type'] ?? '');

        switch ($targetType) {

            case NotificationTargetType::ALL:
                // Nothing to validate.
                return;

            case NotificationTargetType::STAGE:

                // stage_id is required.
                if (empty($data['stage_id'])) {
                    throw new ValidationException([
                        'stage_id' => 'stage_id is required when target_type is "stage"',
                    ]);
                }

                // Stage must exist.
                $stage = $this->stageInterFace->find((int) $data['stage_id']);

                if ($stage === null) {
                    throw new NotFoundException('Stage not found.');
                }

                return;

            case NotificationTargetType::STUDENT:

                // student_id is required.
                if (empty($data['student_id'])) {
                    throw new ValidationException([
                        'student_id' => 'student_id is required when target_type is "student"',
                    ]);
                }

                // Student must exist.
                $student = $this->student->find((int) $data['student_id']);

                if ($student === null) {
                    throw new NotFoundException('Student not found.');
                }

                return;

            case NotificationTargetType::PARENT:

                // Either specific students (each must exist) or nothing at
                // all, which means "every parent in the system".
                if (!empty($data['target_student_ids'])) {
                    if (!is_array($data['target_student_ids'])) {
                        throw new ValidationException([
                            'target_student_ids' => 'target_student_ids must be an array of student IDs',
                        ]);
                    }

                    foreach ($data['target_student_ids'] as $sid) {
                        if ($this->student->find((int) $sid) === null) {
                            throw new NotFoundException("Student not found: {$sid}");
                        }
                    }

                    return;
                }

                if (!empty($data['student_id'])) {
                    if ($this->student->find((int) $data['student_id']) === null) {
                        throw new NotFoundException('Student not found.');
                    }
                }

                return;

            default:
                throw new ValidationException([
                    'target_type' => 'Invalid target_type.',
                ]);
        }
    }
} 