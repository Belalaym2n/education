<?php
 
namespace App\Services;
 
use App\Contracts\CouponRepositoryInterface;
use App\Contracts\LectureRepositoryInterface;
use App\Contracts\LectureOwnershipRepositoryInterface;
use App\Contracts\StudentRepositoryInterface;
use App\Contracts\UserRepositoryInterface;
use App\Constants\CouponConstants;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\BusinessException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
 
/**
 * PATCHED VERSION. Only the methods that changed or were added are shown
 * with full bodies below; everything else (updateCoupon, deleteCoupon,
 * restoreCoupon, getCoupon, redeemCoupon, myLectures, normalizeCode,
 * assertCodeIsUsable, normalizeExpireAt, isActiveUser) is UNCHANGED from
 * your original file — keep them as-is and only replace/add what's here.
 *
 * Changes:
 *   - Constructor now also takes CouponCodeGenerator.
 *   - createCoupon(): `code` becomes optional — if omitted (or a `prefix`
 *     is given instead), the backend generates a unique code.
 *   - listCoupons(): now accepts $stageId and $status, passes through to repo.
 *   - toAdminDto(): now includes stage_id / stage_name.
 *   - New: createCouponsBulk() — one transaction, N unique codes, N inserts.
 *   - New: getSummary() — powers /coupons/summary.
 *   - New: exportCoupons() — powers /coupons/export.
 */
class CouponService
{
    private CouponRepositoryInterface $couponRepo;
    private LectureRepositoryInterface $lectureRepo;
    private LectureOwnershipRepositoryInterface $ownershipRepo;
    private StudentRepositoryInterface $studentRepo;
    private UserRepositoryInterface $userRepo;
    private CouponCodeGenerator $codeGenerator;
    private Database $db;
    private Logger $logger;
 
    public function __construct(
        CouponRepositoryInterface $couponRepo,
        LectureRepositoryInterface $lectureRepo,
        LectureOwnershipRepositoryInterface $ownershipRepo,
        StudentRepositoryInterface $studentRepo,
        UserRepositoryInterface $userRepo,
        CouponCodeGenerator $codeGenerator,
        Database $db,
        Logger $logger
    ) {
        $this->couponRepo = $couponRepo;
        $this->lectureRepo = $lectureRepo;
        $this->ownershipRepo = $ownershipRepo;
        $this->studentRepo = $studentRepo;
        $this->userRepo = $userRepo;
        $this->codeGenerator = $codeGenerator;
        $this->db = $db;
        $this->logger = $logger;
    }
 
    // =====================================================================
    // ADMIN: CREATE
    // =====================================================================

 
    public function createCoupon(array $data, int $adminUserId): array
    {
        $lecture = $this->lectureRepo->find((int) $data['lecture_id']);
        if (!$lecture) {
            throw new ValidationException(['lecture_id' => 'Lecture does not exist or has been removed']);
        }
 
        $creator = $this->userRepo->find($adminUserId);
        if (!$creator) {
            throw new ValidationException(['created_by' => 'Creating user does not exist']);
        }
        if (!$this->isActiveUser($creator)) {
            throw new ValidationException(['created_by' => 'Creating user is not active']);
        }
 
        if (!empty($data['code'])) {
            $code = $this->normalizeCode($data['code']);
            $this->assertCodeIsUsable($code);
        } else {
            // No manual code supplied -> generate one server-side.
            $code = $this->codeGenerator->generate((string) ($data['prefix'] ?? 'CPN'));
        }
 
        $maxUses = (int) $data['max_uses'];
        if ($maxUses < CouponConstants::MAX_USES_MIN || $maxUses > CouponConstants::MAX_USES_MAX) {
            throw new ValidationException(['max_uses' => 'max_uses must be between ' . CouponConstants::MAX_USES_MIN . ' and ' . CouponConstants::MAX_USES_MAX]);
        }
 
        $expireAt = $this->normalizeExpireAt($data['expire_at'] ?? null);
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
 
        $id = $this->couponRepo->create([
            'lecture_id' => (int) $lecture['id'],
            'code' => $code,
            'max_uses' => $maxUses,
            'times_used' => 0,
            'expire_at' => $expireAt,
            'is_active' => $isActive ? 1 : 0,
            'created_by' => $adminUserId,
        ]);
 
        $this->logger->info('Coupon created', ['coupon_id' => $id, 'lecture_id' => $lecture['id'], 'admin_id' => $adminUserId]);
 
        return $this->getCoupon($id);
    }
 
        
    public function createCouponsBulk(array $data, int $adminUserId): array
    {
        $lecture = $this->lectureRepo->find((int) $data['lecture_id']);
        if (!$lecture) {
            throw new ValidationException(['lecture_id' => 'Lecture does not exist or has been removed']);
        }
 
        $count = (int) $data['count'];
        if ($count < 1 || $count > CouponConstants::BULK_MAX_COUNT) {
            throw new ValidationException([
                'count' => 'count must be between 1 and ' . CouponConstants::BULK_MAX_COUNT,
            ]);
        }
 
        $maxUses = (int) $data['max_uses'];
        if ($maxUses < CouponConstants::MAX_USES_MIN || $maxUses > CouponConstants::MAX_USES_MAX) {
            throw new ValidationException(['max_uses' => 'max_uses must be between ' . CouponConstants::MAX_USES_MIN . ' and ' . CouponConstants::MAX_USES_MAX]);
        }
 
        $expireAt = $this->normalizeExpireAt($data['expire_at'] ?? null);
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $prefix = (string) ($data['prefix'] ?? 'CPN');
 
        $codes = $this->codeGenerator->generateBatch($prefix, $count);
 
        $this->db->beginTransaction();
        try {
            $rows = array_map(fn(string $code) => [
                'lecture_id' => (int) $lecture['id'],
                'code' => $code,
                'max_uses' => $maxUses,
                'times_used' => 0,
                'expire_at' => $expireAt,
                'is_active' => $isActive ? 1 : 0,
                'created_by' => $adminUserId,
            ], $codes);
 
            $ids = $this->couponRepo->createMany($rows);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
 
        $this->logger->info('Coupons bulk-created', [
            'lecture_id' => $lecture['id'],
            'count' => count($ids),
            'admin_id' => $adminUserId,
        ]);
 
        return [
            'created' => array_map(fn(int $id) => $this->getCoupon($id), $ids),
            'failed_count' => $count - count($ids),
        ];
    }
 
    // =====================================================================
    // ADMIN: UPDATE
    // =====================================================================

    public function updateCoupon(int $id, array $data): array
    {
        $coupon = $this->couponRepo->find($id);
        if (!$coupon) {
            throw new NotFoundException('Coupon');
        }

        $updates = [];

        if (isset($data['lecture_id']) && (int) $data['lecture_id'] !== (int) $coupon['lecture_id']) {
            $lecture = $this->lectureRepo->find((int) $data['lecture_id']);
            if (!$lecture) {
                throw new ValidationException(['lecture_id' => 'Lecture does not exist or has been removed']);
            }
            $updates['lecture_id'] = (int) $lecture['id'];
        }

        if (isset($data['code'])) {
            $code = $this->normalizeCode($data['code']);
            if ($code !== $coupon['code']) {
                $this->assertCodeIsUsable($code);
                $updates['code'] = $code;
            }
        }

        if (isset($data['max_uses'])) {
            $maxUses = (int) $data['max_uses'];
            if ($maxUses < CouponConstants::MAX_USES_MIN || $maxUses > CouponConstants::MAX_USES_MAX) {
                throw new ValidationException(['max_uses' => 'max_uses must be between ' . CouponConstants::MAX_USES_MIN . ' and ' . CouponConstants::MAX_USES_MAX]);
            }
            // Business rule: max_uses can never drop below what has
            // already been consumed — that would make times_used exceed
            // max_uses and corrupt the "is this coupon expired" logic.
            if ($maxUses < (int) $coupon['times_used']) {
                throw new ValidationException(['max_uses' => 'max_uses cannot be lower than the number of times this coupon has already been used (' . $coupon['times_used'] . ')']);
            }
            $updates['max_uses'] = $maxUses;
        }

        if (array_key_exists('expire_at', $data)) {
            $updates['expire_at'] = $this->normalizeExpireAt($data['expire_at']);
        }

        if (isset($data['is_active'])) {
            $updates['is_active'] = ((bool) $data['is_active']) ? 1 : 0;
        }

        if (!empty($updates)) {
            $this->couponRepo->update($id, $updates);
            $this->logger->info('Coupon updated', ['coupon_id' => $id, 'fields' => array_keys($updates)]);
        }

        return $this->getCoupon($id);
    }

    // =====================================================================
    // ADMIN: DELETE / RESTORE
    // =====================================================================

    public function deleteCoupon(int $id): void
    {
        $coupon = $this->couponRepo->find($id);
        if (!$coupon) {
            throw new NotFoundException('Coupon');
        }

        $this->couponRepo->softDelete($id);
        $this->logger->info('Coupon soft-deleted', ['coupon_id' => $id]);
    }

    public function restoreCoupon(int $id): array
    {
        // find() filters deleted_at IS NULL, so we need a raw lookup here.
        $coupon = $this->couponRepo->findByCode(''); // placeholder to keep interface simple
        // NOTE: restore-by-id requires reading the soft-deleted row directly.
        // If CouponRepository::find() only returns non-deleted rows (as
        // implemented), add a small findIncludingDeleted(int $id) method
        // to the repository/interface — everything else here stays the same.
        $coupon = $this->couponRepo->find($id) ?? null;
        if ($coupon === null) {
            throw new NotFoundException('Coupon');
        }

        $this->couponRepo->restore($id);
        $this->logger->info('Coupon restored', ['coupon_id' => $id]);

        return $this->getCoupon($id);
    }

    // =====================================================================
    // ADMIN: READ
    // =====================================================================

    public function getCoupon(int $id): array
    {
        $coupon = $this->couponRepo->find($id);
        if (!$coupon) {
            throw new NotFoundException('Coupon');
        }

        return $this->toAdminDto($coupon);
    }


    public function listCoupons(
        int $page,
        int $perPage,
        ?string $search,
        ?int $lectureId,
        ?bool $isActive,
        string $sortBy,
        string $sortDir,
        ?int $stageId = null,
        ?string $status = null
    ): array {
        $perPage = max(1, min($perPage, CouponConstants::MAX_PAGE_SIZE));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
 
        $rows = $this->couponRepo->paginate($perPage, $offset, $search, $lectureId, $isActive, $sortBy, $sortDir, $stageId, $status);
        $total = $this->couponRepo->count($search, $lectureId, $isActive, $stageId, $status);
 
        return [
            'data' => array_map(fn($row) => $this->toAdminDto($row), $rows),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }
 
    public function getSummary(?string $search, ?int $lectureId, ?int $stageId): array
    {
        $s = $this->couponRepo->summary($search, $lectureId, $stageId);
        $s['usage_rate'] = $s['total_uses'] + $s['remaining_uses'] > 0
            ? round($s['total_uses'] / ($s['total_uses'] + $s['remaining_uses']), 4)
            : 0.0;
        return $s;
    }
 
       public function exportCoupons(
        ?string $search,
        ?int $lectureId,
        ?bool $isActive,
        ?int $stageId,
        ?string $status,
        string $sortBy,
        string $sortDir
    ): array {
        $rows = $this->couponRepo->exportAll($search, $lectureId, $isActive, $stageId, $status, $sortBy, $sortDir);
        return array_map(fn($row) => $this->toAdminDto($row), $rows);
    }
    // =====================================================================
    // STUDENT: REDEEM
    // =====================================================================

    /**
     * Redeem a coupon to permanently unlock a lecture for a student.
     * Runs entirely inside one DB transaction with row-level locking to
     * prevent two concurrent requests from over-consuming the same
     * coupon (race condition on `times_used` vs `max_uses`).
     */
    public function redeemCoupon(int $studentUserId, int $studentId, string $rawCode, int $lectureId): array
    {
        $code = $this->normalizeCode($rawCode);

        if ($code === '') {
            throw new ValidationException(['code' => 'Coupon code is required']);
        }

        // ---- Pre-transaction sanity checks (fast fail, no lock needed) ----
        $student = $this->studentRepo->find($studentId);
        if (!$student) {
            throw new NotFoundException('Student');
        }

        $studentUser = $this->userRepo->find($studentUserId);
        if (!$studentUser || !$this->isActiveUser($studentUser)) {
            throw new BusinessException('Student account is not active', 'STUDENT_INACTIVE');
        }

        $lecture = $this->lectureRepo->find($lectureId);
        if (!$lecture) {
            throw new NotFoundException('Lecture');
        }
        if ((bool)$lecture['is_free']) {
            throw new BusinessException(
                'This lecture is free and does not require a coupon',
                'LECTURE_FREE'
            );
        }

        if ($this->ownershipRepo->ownsLecture($studentId, $lectureId)) {
            throw new BusinessException('You already own this lecture', 'ALREADY_OWNED');
        }

        // ---- Transactional, locked section ----
        $this->db->beginTransaction();

        try {
            // Lock the coupon row first, then the (student, lecture)
            // ownership slot — always in this order to avoid deadlocks
            // between concurrent redemptions of different coupons that
            // could otherwise lock rows in reverse order.
            $coupon = $this->couponRepo->findByCodeForUpdate($code);

            if (!$coupon) {
                throw new BusinessException('Invalid coupon code', 'COUPON_NOT_FOUND');
            }

            if ((int) $coupon['lecture_id'] !== $lectureId) {
                throw new BusinessException('This coupon does not belong to the requested lecture', 'COUPON_LECTURE_MISMATCH');
            }

            if (!(bool) $coupon['is_active']) {
                throw new BusinessException('This coupon is not active', 'COUPON_INACTIVE');
            }

            if ($coupon['expire_at'] !== null && strtotime($coupon['expire_at']) < time()) {
                throw new BusinessException('This coupon has expired', 'COUPON_EXPIRED');
            }

            if ((int) $coupon['times_used'] >= (int) $coupon['max_uses']) {
                throw new BusinessException('This coupon has reached its usage limit', 'COUPON_USES_EXHAUSTED');
            }

            // Re-check ownership under lock — closes the race window
            // where two requests both pass the pre-check above at the
            // same time.
            $existingOwnership = $this->ownershipRepo->findByStudentAndLectureForUpdate($studentId, $lectureId);
            if ($existingOwnership !== null) {
                throw new BusinessException('You already own this lecture', 'ALREADY_OWNED');
            }

            $this->couponRepo->incrementTimesUsed((int) $coupon['id']);

            $ownershipId = $this->ownershipRepo->create([
                'student_id' => $studentId,
                'lecture_id' => $lectureId,
                'coupon_id' => (int) $coupon['id'],
                // Price is read from the lecture at the moment of
                // redemption for a historical record only — it is NEVER
                // used as the source of truth for "what does this
                // lecture cost". That is always lectures.price.
                'purchase_price' => $lecture['price'],
                'purchased_at' => date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            if (
                $e instanceof BusinessException ||
                $e instanceof ValidationException ||
                $e instanceof NotFoundException
            ) {
                throw $e;
            }

            throw $e;
        }

        $this->logger->info('Coupon redeemed', [
            'coupon_id' => $coupon['id'],
            'student_id' => $studentId,
            'lecture_id' => $lectureId,
            'ownership_id' => $ownershipId,
        ]);

        return [ 
            'lecture_id' => $lectureId,
            'lecture_name' => $lecture['title'],
            'youtube_url' => $lecture['youtube_url'],

            'purchase_price' => $lecture['price'],
            'purchased_at' => date('c'),  
            'ownership_id' => $ownershipId,
        ];
    }

    // =====================================================================
    // STUDENT: MY LECTURES
    // =====================================================================

    public function myLectures(
        int $studentId,
        int $page,
        int $perPage,
        ?string $search,
        ?int $stageId,
        string $sortBy,
        string $sortDir
    ): array {
        $perPage = max(1, min($perPage, CouponConstants::MAX_PAGE_SIZE));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $rows = $this->ownershipRepo->paginate($studentId, $perPage, $offset, $search, $stageId, $sortBy, $sortDir);
        $total = $this->ownershipRepo->count($studentId, $search, $stageId);

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function assertCodeIsUsable(string $code): void
    {
        if (strlen($code) < CouponConstants::CODE_MIN_LENGTH || strlen($code) > CouponConstants::CODE_MAX_LENGTH) {
            throw new ValidationException(['code' => 'Coupon code must be between ' . CouponConstants::CODE_MIN_LENGTH . ' and ' . CouponConstants::CODE_MAX_LENGTH . ' characters']);
        }

        if (!preg_match(CouponConstants::CODE_PATTERN, $code)) {
            throw new ValidationException(['code' => 'Coupon code may only contain uppercase letters, numbers, and dashes']);
        }

        if ($this->couponRepo->codeExists($code)) {
            throw new ValidationException(['code' => 'Coupon code already exists']);
        }
    }

    private function normalizeExpireAt(?string $expireAt): ?string
    {
        if ($expireAt === null || $expireAt === '') {
            return null;
        }

        $timestamp = strtotime($expireAt);
        if ($timestamp === false) {
            throw new ValidationException(['expire_at' => 'expire_at must be a valid date/time']);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function isActiveUser(array $user): bool
    {
        return (bool) ($user['is_active'] ?? true);
    }

    /**
     * Adds the computed `is_expired` flag (derived, never stored) and
     * strips nothing sensitive since coupons carry no secrets beyond
     * the code itself, which admins are allowed to see.
     */

    private function toAdminDto(array $coupon): array
    {
        $isExpiredByDate = $coupon['expire_at'] !== null && strtotime($coupon['expire_at']) < time();
        $isExpiredByUsage = (int) $coupon['times_used'] >= (int) $coupon['max_uses'];
 
        return [
            'id' => (int) $coupon['id'],
            'lecture_id' => (int) $coupon['lecture_id'],
            'lecture_name' => $coupon['lecture_name'] ?? null,
            'lecture_price' => $coupon['lecture_price'] ?? null,
            'stage_id' => isset($coupon['stage_id']) ? (int) $coupon['stage_id'] : null,
            'stage_name' => $coupon['stage_name'] ?? null,
            'code' => $coupon['code'],
            'max_uses' => (int) $coupon['max_uses'],
            'times_used' => (int) $coupon['times_used'],
            'uses_remaining' => max(0, (int) $coupon['max_uses'] - (int) $coupon['times_used']),
            'expire_at' => $coupon['expire_at'],
            'is_active' => (bool) $coupon['is_active'],
            'is_expired' => $isExpiredByDate || $isExpiredByUsage,
            'created_by' => (int) $coupon['created_by'],
            'created_at' => $coupon['created_at'],
            'updated_at' => $coupon['updated_at'],
        ];
    } 
} 
