<?php

namespace App\Controllers;

use App\Contracts\AttendanceRepositoryInterface;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Validators\Validator;

class AttendanceController
{
    private AttendanceService $attendanceService;
    private AttendanceRepositoryInterface $attendanceRepo;
    private NotificationService $notificationService;
 
     public function __construct(
        AttendanceService $attendanceService,
        AttendanceRepositoryInterface $attendanceRepo,
        NotificationService $notificationService
    ) {
        $this->attendanceService = $attendanceService;
        $this->attendanceRepo = $attendanceRepo;
        $this->notificationService = $notificationService;
    }

    /**
     * POST /api/attendance/scan — unchanged from Task 2's backend.
     */
    public function scan(array $requestData): array
    {
        $validator = new Validator($requestData);
        $validator->required('qr_code');
        $validator->required('message');
        $validator->validateString('qr_code', 4, 64);
        $validator->validateString('message', 1, 255);

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $validator->getSanitized(['qr_code', 'message']);
        $recordedBy = (int) ($_SERVER['auth_user_id'] ?? 0);

        $result = $this->attendanceService->recordAttendance(
            $clean['qr_code'],
            $clean['message'],
            $recordedBy
        );

        return ['success' => true, 'data' => $result];
    }

    /**
     * POST /api/attendance/bulk — unchanged from Task 2's backend.
     */
    public function bulk(array $requestData): array
    {
        $records = $requestData['records'] ?? $requestData;

        if (!is_array($records) || empty($records)) {
            throw new ValidationException(['records' => 'records must be a non-empty array']);
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new ValidationException(['records' => 'each record must be an object with qr_code and message']);
            }
        }

        $recordedBy = (int) ($_SERVER['auth_user_id'] ?? 0);
        $result = $this->attendanceService->recordBulkAttendance($records, $recordedBy);

        return ['success' => true, 'data' => $result];
    }

    /**
     * GET /api/attendance/records
     * Filters: date, date_from, date_to, stage_id, status, qr_code,
     *          student_name, father_name, search
     * Paging:  limit, offset, sort, order
     */
   public function index(array $queryParams = []): array
{
    if (empty($queryParams)) {
        $queryParams = $_GET;
    }

    $filters = $this->extractFilters($queryParams);

    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
    $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
    $sort = (string)($queryParams['sort'] ?? 'recorded_at');
    $order = (string)($queryParams['order'] ?? 'desc');

    $result = $this->attendanceRepo->paginate(
        $filters,
        $limit,
        $offset,
        $sort,
        $order
    );

    return [
        'success' => true,
        'data' => $result['data'],
        'meta' => [
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ],
    ];
} 

    /**
     * GET /api/attendance/statistics
     * Same $filters shape as index() so cards stay in sync with the table
     * when the admin applies filters on the dashboard.
     */
public function statistics(array $queryParams = []): array
{
    if (empty($queryParams)) {
        $queryParams = $_GET;
    }

    $filters = $this->extractFilters($queryParams);
    $stats = $this->attendanceRepo->statistics($filters);

    return [
        'success' => true,
        'data' => $stats,
    ];
} 
    /**
     * POST /api/attendance/{id}/notify
     * Sends a custom message to the student's linked parent(s) via the
     * existing NotificationService, then links the resulting notification
     * back onto the attendance row.
     */
    public function notifyParent(int $attendanceId, array $requestData): array
    {
        $validator = new Validator($requestData);
        $validator->required('message');
        $validator->validateString('message', 1, 500);

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $record = $this->attendanceRepo->find($attendanceId);
        if ($record === null) {
            throw new NotFoundException('Attendance record not found.');
        }

        $clean = $validator->getSanitized(['message']);
        $createdBy = (int) ($_SERVER['auth_user_id'] ?? 0);

        // Signature assumed from AttendanceService's (currently commented
        // out) usage: notifyParentsOfStudents(studentIds, title, message,
        // createdBy) -> ['id' => notificationId, ...]. Adjust here if the
        // real NotificationService signature differs.
        $notification = $this->notificationService->notifyParentsOfStudents(
            [(int) $record['student_id']],
            'Attendance Update',
            $clean['message'],
            $createdBy
        );

        if (!empty($notification['id'])) {
            $this->attendanceRepo->attachNotification($attendanceId, (int) $notification['id']);
        }

        return [
            'success' => true,
            'data' => [
                'attendance_id' => $attendanceId,
                'notification_id' => $notification['id'] ?? null,
            ],
        ];
    }

    private function extractFilters(array $queryParams): array
    {
        return array_filter([
            'date' => $queryParams['date'] ?? null,
            'date_from' => $queryParams['date_from'] ?? null,
            'date_to' => $queryParams['date_to'] ?? null,
            'stage_id' => $queryParams['stage_id'] ?? null,
            'status' => $queryParams['status'] ?? null,
            'qr_code' => $queryParams['qr_code'] ?? null,
            'student_name' => $queryParams['student_name'] ?? null,
            'father_name' => $queryParams['father_name'] ?? null,
            'search' => $queryParams['search'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}