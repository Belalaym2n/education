<?php

namespace App\Services;

use App\Contracts\AttendanceRepositoryInterface;
use App\Contracts\FatherStudentRepositoryInterface;
use App\Contracts\StudentRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\NotFoundException;

/**
 * Owns the "scan QR -> find student -> find parent -> notify" flow for both
 * the single live-scan endpoint and the offline bulk-sync endpoint. All
 * notification dispatch is delegated to NotificationService — this class
 * never touches Firebase or notification_recipients directly.
 */
class AttendanceService
{
    private AttendanceRepositoryInterface $attendanceRepo;
    private StudentRepositoryInterface $studentRepo;
    private FatherStudentRepositoryInterface $fatherStudentRepo;
    private NotificationService $notificationService;
    private Database $db;
    private Logger $logger;
 
    public function __construct(
        AttendanceRepositoryInterface $attendanceRepo,
        StudentRepositoryInterface $studentRepo,
        FatherStudentRepositoryInterface $fatherStudentRepo,
        NotificationService $notificationService,
        Database $db,
        Logger $logger 
    ) {
        $this->attendanceRepo = $attendanceRepo;
        $this->studentRepo = $studentRepo;
        $this->fatherStudentRepo = $fatherStudentRepo;
        $this->notificationService = $notificationService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Single live scan: validate QR -> find student -> find linked parent(s)
     * -> notify -> log. Throws NotFoundException on invalid QR / no parent,
     * which the controller turns into a 404 response.
     */
    public function recordAttendance(string $qrCode, string $message, int $recordedBy): array
    {
        $student = $this->studentRepo->
        findByQrCode($qrCode);

        if ($student === null) {
            throw new NotFoundException
            ('Invalid QR code: student not found.');
        }

        $studentId = (int) $student['id'];

        $fatherUserIds = $this->
        fatherStudentRepo->
        findFatherUserIdsByStudentId($studentId);

        if (empty($fatherUserIds)) {
            throw new NotFoundException('No linked parent found for this student.');
        }

        $this->db->beginTransaction();

        try {
            // $notification = 
            // $this->notificationService->
            // notifyParentsOfStudenZts(
            //     [$studentId],
            //     'Attendance Update',
            //     $message,
            //     $recordedBy
            // );

            $attendanceId = $this->
            attendanceRepo->create([
                'student_id' => $studentId,
                'status' => $this->
                inferStatus($message),
                'message' => $message,
                // 'notification_id' => $notification['id'] ?? null,
                'recorded_by' => $recordedBy,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Attendance recording failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return [
            'attendance_id' => $attendanceId,
            'student_id' => $studentId,
            'parents_notified' => 
            count($fatherUserIds),
        ];
    }

    /**
     * Bulk (offline-sync) attendance. Every row is judged independently — one
     * bad row never aborts the batch. Optimized to avoid N+1 queries:
     *
     *   1. ONE query resolves every QR code to a student.
     *   2. ONE query finds which of those students have a linked parent.
     *   3. Records are grouped by identical message text, so students who
     *      were all marked e.g. "Absent" share a SINGLE notification (and a
     *      single Firebase fan-out call inside NotificationService), instead
     *      of one notification per student.
     *
     * @param array<int, array{qr_code?: string, message?: string}> $records
     */
    public function recordBulkAttendance(array $records, int $recordedBy): array
    {
        $qrToEntries = [];
        $failed = [];

        // Step 1: normalize input, bucket by QR code (dedupe DB round-trips).
        foreach ($records as $index => $record) {
            $qrCode = trim((string) ($record['qr_code'] ?? ''));
            $message = trim((string) ($record['message'] ?? ''));

            if ($qrCode === '' || $message === '') {
                $failed[] = ['index' => $index, 'qr_code' => $qrCode, 'reason' => 'Invalid QR'];
                continue;
            }

            $qrToEntries[$qrCode][] = ['index' => $index, 'message' => $message];
        }

        if (empty($qrToEntries)) {
            return $this->summarize(0, $failed);
        }

        // Step 2: ONE query resolves every QR code at once.
        $students = $this->studentRepo->findManyByQrCodes(array_keys($qrToEntries));
        $studentByQr = [];
        foreach ($students as $student) {
            $studentByQr[$student['qr_code']] = $student;
        }

        foreach (array_keys($qrToEntries) as $qrCode) {
            if (!isset($studentByQr[$qrCode])) {
                foreach ($qrToEntries[$qrCode] as $entry) {
                    $failed[] = ['index' => $entry['index'], 'qr_code' => $qrCode, 'reason' => 'Student Not Found'];
                }
                unset($qrToEntries[$qrCode]);
            }
        }

        if (empty($qrToEntries)) {
            return $this->summarize(0, $failed);
        }

        // Step 3: ONE query finds which of those students have a linked parent.
        $studentIds = array_map(fn($qr) => (int) $studentByQr[$qr]['id'], array_keys($qrToEntries));
        $studentsWithParent = array_flip($this->fatherStudentRepo->findStudentIdsWithAnyFather($studentIds));

        // Step 4: bucket valid rows by identical message so each distinct
        // message becomes ONE batched notification instead of one-per-student.
        $groups = [];   // message => [student_id, ...]
        $rowsToLog = []; // rows for attendance_records, tagged with original index
        $processed = 0;

        foreach ($qrToEntries as $qrCode => $entries) {
            $studentId = (int) $studentByQr[$qrCode]['id'];

            foreach ($entries as $entry) {
                if (!isset($studentsWithParent[$studentId])) {
                    $failed[] = ['index' => $entry['index'], 'qr_code' => $qrCode, 'reason' => 'Parent Not Found'];
                    continue;
                }

                $groups[$entry['message']][] = $studentId;
                $rowsToLog[] = [
                    '_index' => $entry['index'],
                    'student_id' => $studentId,
                    'status' => $this->inferStatus($entry['message']),
                    'message' => $entry['message'],
                    'recorded_by' => $recordedBy,
                ];
                $processed++;
            }
        }

        // Step 5: one notification-create (+ one dispatch fan-out) per
        // distinct message, then log every row in one transaction.
        $this->db->beginTransaction();

        try {
            $notificationIdByMessage = [];

            foreach ($groups as $message => $groupStudentIds) {
                try {
                    // $notification = $this->
                    // notificationService->
                    // notifyParentsOfStudents(
                    //     array_values(array_unique($groupStudentIds)),
                    //     'Attendance Update',
                    //     $message,
                    //     $recordedBy
                    // );
                    // $notificationIdByMessage[$message] = $notification['id'] ?? null;
             
             
                    } catch (\Throwable $e) {
                    // Notification failed for this message-group only; the
                    // attendance rows for it are still logged, but flagged.
                    $this->logger->error('Bulk attendance notification failed', [
                        'message' => $message,
                        'error' => $e->getMessage(),
                    ]);
                    $notificationIdByMessage[$message] = null;

                    foreach ($rowsToLog as $row) {
                        if ($row['message'] === $message) {
                            $failed[] = ['index' => $row['_index'], 'qr_code' => null, 'reason' => 'Notification Failed'];
                        }
                    }
                }
            }

            foreach ($rowsToLog as $row) {
                $index = $row['_index'];
                unset($row['_index']);
                $row['notification_id'] = $notificationIdByMessage[$row['message']] ?? null;
                $this->attendanceRepo->create($row);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Bulk attendance persistence failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return $this->summarize($processed, $failed);
    } 

    private function summarize(int $processed, array $failed): array
    {
        return [
            'processed' => $processed,
            'failed_count' => count($failed),
            'failed' => $failed,
        ];
    }

    private function inferStatus(string $message): string
    {
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'absent')) {
            return 'absent';
        }

        if (str_contains($lower, 'late')) {
            return 'late';
        }

        return 'present';
    }
}