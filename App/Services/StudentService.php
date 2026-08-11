<?php

namespace App\Services;

use App\Contracts\FatherRepositoryInterface;
use App\Contracts\FatherStudentRepositoryInterface;
use App\Contracts\StudentRepositoryInterface;
use App\Contracts\UserRepositoryInterface;
use App\Core\Config;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;

class StudentService
{
    /** Role id for the auto-created guardian account (see `roles` table: 2 = father). */
    private const FATHER_ROLE_ID = '2';

    private StudentRepositoryInterface $studentRepo;
    private UserRepositoryInterface $userRepo;
    private FatherRepositoryInterface $fatherRepo;
    private FatherStudentRepositoryInterface $fatherStudentRepo;
    private UserService $userService;
    private AuthService $authService;
    private Database $db;
    private Logger $logger;

    public function __construct(
        StudentRepositoryInterface $studentRepo,
        UserRepositoryInterface $userRepo,
        FatherRepositoryInterface $fatherRepo,
        FatherStudentRepositoryInterface $fatherStudentRepo,
        UserService $userService,
        AuthService $authService,
        Database $db,
        Logger $logger
    ) {
        $this->studentRepo = $studentRepo;
        $this->userRepo = $userRepo;
        $this->fatherRepo = $fatherRepo;
        $this->fatherStudentRepo = $fatherStudentRepo;
        $this->userService = $userService;
        $this->authService = $authService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Registers a new student AND auto-provisions their father/guardian account,
     * all inside one transaction. If any step fails, everything rolls back —
     * there is never a student without a matching guardian account, or vice versa.
     */
    public function registerStudent(array $data): array
    {
        $this->userService->checkDuplicateEmail($data['email']);

        $this->db->beginTransaction();

        $studentId = null;
        $fatherId = null;
        $fatherUserId = null;
        $fatherEmail = null;
        $qrCode = null;

        try {
            // The student and the auto-created father share the exact same
            // password. We hash it once and reuse the hash for both rows —
            // there is no requirement that the hash *string* differ, only
            // that the plaintext is never stored, and password_verify()
            // against either row works identically.
            $passwordHash = $this->userService->hashPassword($data['password']);

            // 1. Create the student's own user account.
            $userId = $this->userRepo->create([
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password_hash' => $passwordHash,
                'role_id' => $data['role_id'],
                'is_active' => 1,
            ]);

            // 2. Create the Student profile, with a fresh opaque QR identity token.
            $qrCode = $this->generateUniqueQrCode();

            $studentId = $this->studentRepo->create([
                'user_id' => $userId,
                'stage_id' => $data['stage_id'],
                'parent_phone' => $data['parent_phone'],
                'qr_code' => $qrCode,
            ]);

            // 3. Auto-create the Father/Guardian user account. The frontend
            //    never supplies a role or email for this account — both are
            //    decided entirely by the backend, per spec.
            $fatherEmail = $this->generateFatherEmail($userId);

            $fatherUserId = $this->userRepo->create([
                'full_name' => $data['full_name'].'- Father',
                'email' => $fatherEmail,
                'phone' => $data['parent_phone'],
                'password_hash' => $passwordHash,
                'role_id' => self::FATHER_ROLE_ID,
                'is_active' => 1,
            ]);

            $fatherId = $this->fatherRepo->create(['user_id' => $fatherUserId]);

            // 4. Link them via father_student (many-to-many, supports
            //    additional guardians later without any schema change).
            $this->fatherStudentRepo->link($fatherId, $studentId, 'father', true);
 
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error("Student registration failed", ['error' => $e->getMessage()]);
            throw $e;
        }

        $user = $this->studentRepo->find($userId);

        $token = $this->authService->generateToken(
            array_merge($user, ['id' => $userId])
        );

        $this->logger->info("New student registered", [
            'user_id' => $userId,
            'student_id' => $studentId,
            'father_user_id' => $fatherUserId,
        ]);

        return [
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $user['role_name'], // student
                'is_active' => (bool) $user['is_active'],
            ],
            'student' => [
                'id' => (int) $studentId,
                'qr_code' => $qrCode,
                'stage' => [
                    'id' => (int) $user['stage_id'],
                    'name' => $user['stage_name'],
                ],
            ],
            'father' => [
                'id' => (int) $fatherId,
                'user_id' => (int) $fatherUserId,
                'email' => $fatherEmail,
            ],
            'token' => $token,
        ];
    }

    /**
     * <PlatformPrefix><StudentUserID>@gmail.com — prefix lives in config,
     * never hardcoded at each call site. Deterministic on the student's
     * user_id, so it is guaranteed unique given users.email is unique.
     */
    private function generateFatherEmail(int $studentUserId): string
    {
        $prefix = Config::get('father.email_prefix') ?: 'gamal-educ';

        return $prefix . $studentUserId . '@gmail.com';
    }

    /**
     * Opaque QR identity token. Never the raw student_id/user_id — those are
     * guessable sequential integers, and printing them on a physical QR code
     * would let anyone submit forged attendance for another student.
     */
    private function generateUniqueQrCode(): string
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(8))); // 16 hex chars
            $exists = $this->studentRepo->findByQrCode($code);
        } while ($exists !== null);

        return $code;
    }

    public function getStudent(int $id): array
    {
        $student = $this->studentRepo->find($id);

        if (!$student) {
            throw new NotFoundException("الطالب المطلوب غير موجود.");
        }

        return $student;
    }

    public function listStudents(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        return $this->studentRepo->all($filters, $limit, $offset, $sort, $order);
    }

    public function updateStudent(int $id, array $data): array
    {
        $student = $this->getStudent($id);

        // ==========================
        // Update students table
        // ==========================
        $studentData = array_intersect_key(
            $data,
            array_flip([
                'stage_id',
                'parent_phone',
                'profile_image',
                'enrollment_date',
            ])
        );

        if (!empty($studentData)) {
            $affected = $this->studentRepo->update(
                (int) $student['student_id'],
                $studentData
            );

            $this->logger->info('Students table updated', [
                'student_id' => $student['student_id'],
                'affected_rows' => $affected,
                'data' => $studentData,
            ]);
        }

        // ==========================
        // Update users table
        // ==========================
        $userData = array_intersect_key(
            $data,
            array_flip([
                'full_name',
                'email',
                'phone',
                'is_active',
            ])
        );

        if (!empty($userData)) {
            $affected = $this->userRepo->update(
                $id,
                $userData
            );

            $this->logger->info('Users table updated', [
                'user_id' => $student['id'],
                'affected_rows' => $affected,
                'data' => $userData,
            ]);
        }

        // Reload updated student
        $updated = $this->getStudent($id);

        $this->logger->info('Final student after update', $updated);

        return $updated;
    }

    public function deleteStudent(int $id): void
    {
        $student = $this->getStudent($id); // يتأكد إنه موجود

        if (!$student) {
            throw new NotFoundException('User not found');
        }

        $this->db->beginTransaction();

        try {
            $this->studentRepo->delete($student['student_id']);
            $this->userRepo->delete($id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info("Student deleted", ['id' => $id]);

        // NOTE (edge case, not auto-handled): this does not delete or
        // deactivate the linked father account, since a father may guard
        // multiple students. If this is the student's only linked child,
        // consider a follow-up admin action to deactivate the guardian too.
    }
}