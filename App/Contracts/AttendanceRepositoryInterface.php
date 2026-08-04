<?php

namespace App\Contracts;

interface AttendanceRepositoryInterface
{
    public function create(array $data): int;

    /**
     * Single record lookup — used by the notify-parent endpoint to resolve
     * student_id before dispatching.
     */
    public function find(int $id): ?array;

    /** 
     * Paginated, filtered, sorted list for the Records API / Dashboard
     * table. Returns ['data' => [...], 'total' => int].
     *
     * Supported $filters keys: date, date_from, date_to, stage_id, status,
     * student_name, father_name, qr_code, search (matches student OR
     * father name OR qr_code).
     */
    public function paginate(array $filters, int $limit, int $offset, string $sort, string $order): array;

    /**
     * Aggregate counters for the Statistics widgets — respects the same
     * $filters shape as paginate() so cards stay in sync with the table.
     */
    public function statistics(array $filters): array;

    /**
     * Called after a notification has been sent for an existing attendance
     * row, to link attendance_records.notification_id -> notifications.id.
     */
    public function attachNotification(int $attendanceId, int $notificationId): int;
}