<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Exceptions\ForbiddenException;
use App\Services\CouponService;
use App\Services\StudentService;
use App\Validators\Validator;
use App\Constants\CouponConstants;

/**
 * PATCHED VERSION. show()/destroy()/restore()/redeem()/myLectures()/
 * resolveStudentId() are UNCHANGED — keep them as in your original file.
 * index(), store() were extended; bulkStore(), summary(), export() are new.
 */
class CouponController
{
    private CouponService $couponService;
    private StudentService $studentService;

    public function __construct(CouponService $couponService, StudentService $studentService)
    {
        $this->couponService = $couponService;
        $this->studentService = $studentService;
    }

    // ===================== ADMIN =====================

    public function index(): array
    {
        $page = (int) ($_GET['page'] ?? 1);
        $perPage = (int) ($_GET['per_page'] ?? 20);
        $search = isset($_GET['search']) && $_GET['search'] !== '' ? (string) $_GET['search'] : null;
        $lectureId = isset($_GET['lecture_id']) ? (int) $_GET['lecture_id'] : null;
        $stageId = isset($_GET['stage_id']) ? (int) $_GET['stage_id'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;

        $isActive = null;
        if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
            $isActive = filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $sortBy = (string) ($_GET['sort_by'] ?? 'created_at');
        $sortDir = (string) ($_GET['sort_dir'] ?? 'DESC');

        $result = $this->couponService->listCoupons(
            $page, $perPage, $search, $lectureId, $isActive, $sortBy, $sortDir, $stageId, $status
        );

        return ['success' => true, 'data' => $result['data'], 'meta' => $result['meta']];
    }

    public function summary(): array
    {
        $search = isset($_GET['search']) && $_GET['search'] !== '' ? (string) $_GET['search'] : null;
        $lectureId = isset($_GET['lecture_id']) ? (int) $_GET['lecture_id'] : null;
        $stageId = isset($_GET['stage_id']) ? (int) $_GET['stage_id'] : null;

        return ['success' => true, 'data' => $this->couponService->getSummary($search, $lectureId, $stageId)];
    }

    public function export(): array
    {
        $search = isset($_GET['search']) && $_GET['search'] !== '' ? (string) $_GET['search'] : null;
        $lectureId = isset($_GET['lecture_id']) ? (int) $_GET['lecture_id'] : null;
        $stageId = isset($_GET['stage_id']) ? (int) $_GET['stage_id'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
        $isActive = null;
        if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
            $isActive = filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        $sortBy = (string) ($_GET['sort_by'] ?? 'created_at');
        $sortDir = (string) ($_GET['sort_dir'] ?? 'DESC');

        $rows = $this->couponService->exportCoupons($search, $lectureId, $isActive, $stageId, $status, $sortBy, $sortDir);

        return ['success' => true, 'data' => $rows];
    }

    public function show(int $id): array
    {
        return ['success' => true, 
        'data' => $this->couponService->
        getCoupon($id)];
    }

    public function store(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('lecture_id');
        $validator->required('max_uses');
        // `code` is now OPTIONAL — if omitted, the backend generates one
        // (optionally seeded by `prefix`).

        if (isset($requestData['lecture_id'])) {
            $validator->validateNumber('lecture_id', 1);
        }
        if (isset($requestData['max_uses'])) {
            $validator->validateNumber('max_uses', 1);
        }
        if (isset($requestData['code'])) {
            $validator->validateString('code', 1, 50);
        }
        if (isset($requestData['prefix'])) {
            $validator->validateString('prefix', 1, 12);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $adminUserId = (int) ($_SERVER['auth_user_id'] ?? 0);

        $data = [
            'lecture_id' => (int) $requestData['lecture_id'],
            'code' => $requestData['code'] ?? null,
            'prefix' => $requestData['prefix'] ?? null,
            'max_uses' => (int) $requestData['max_uses'],
            'expire_at' => $requestData['expire_at'] ?? null,
            'is_active' => $requestData['is_active'] ?? true,
        ];

        return ['success' => true, 'data' => $this->couponService->createCoupon($data, $adminUserId)];
    }

    public function bulkStore(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('lecture_id');
        $validator->required('count');
        $validator->required('max_uses');

        if (isset($requestData['lecture_id'])) { 
            $validator->validateNumber('lecture_id', 1);
        }
        if (isset($requestData['count'])) {
            $validator->validateNumber
            ('count', 1,100);
        } 
        if (isset($requestData['max_uses'])) {
            $validator->validateNumber('max_uses', 1);
        }
        if (isset($requestData['prefix'])) {
            $validator->validateString('prefix', 1, 12);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $adminUserId = (int) ($_SERVER['auth_user_id'] ?? 0);

        $data = [
            'lecture_id' => (int) $requestData['lecture_id'],
            'count' => (int) $requestData['count'],
            'max_uses' => (int) $requestData['max_uses'],
            'expire_at' => $requestData['expire_at'] ?? null,
            'is_active' => $requestData['is_active'] ?? true,
            'prefix' => $requestData['prefix'] ?? 'CPN',
        ];

        $result = $this->couponService->createCouponsBulk($data, $adminUserId);

        return ['success' => true, 'data' => $result];
    }

    public function update(int $id, array $requestData): array
    {
        $validator = new Validator($requestData);

        if (isset($requestData['lecture_id'])) {
            $validator->validateNumber('lecture_id', 1);
        }
        if (isset($requestData['max_uses'])) {
            $validator->validateNumber('max_uses', 1);
        }
        if (isset($requestData['code'])) {
            $validator->validateString('code', 1, 50);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $data = [];
        foreach (['lecture_id', 'code', 'max_uses', 'is_active'] as $field) {
            if (isset($requestData[$field])) {
                $data[$field] = $requestData[$field];
            }
        }
        if (array_key_exists('expire_at', $requestData)) {
            $data['expire_at'] = $requestData['expire_at'];
        }

        return ['success' => true, 'data' => $this->couponService->updateCoupon($id, $data)];
    }

    public function destroy(int $id): array
    {
        $this->couponService->deleteCoupon($id);
        return ['success' => true, 'message' => 'Coupon deleted'];
    }

    public function restore(int $id): array
    {
        return ['success' => true, 'data' => $this->couponService->restoreCoupon($id)];
    }

        public function redeem(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('code');
        $validator->required('lecture_id');

        if (isset($requestData['lecture_id'])) {
            $validator->validateNumber('lecture_id', 1);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $authUserId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $studentId = $this->resolveStudentId($authUserId);

        $result = $this->couponService->redeemCoupon(
            $authUserId,
            $studentId,
            (string) $requestData['code'],
            (int) $requestData['lecture_id']
        ); 

        return ['success' => true, 'data' => $result];
    } 
     public function myLectures(): array
    {
        $authUserId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $studentId = $this->resolveStudentId($authUserId);

        $page = (int) ($_GET['page'] ?? 1);
        $perPage = (int) ($_GET['per_page'] ?? 20);
        $search = isset($_GET['search']) && $_GET['search'] !== '' ? (string) $_GET['search'] : null;
        $stageId = isset($_GET['stage_id']) ? (int) $_GET['stage_id'] : null;
        $sortBy = (string) ($_GET['sort_by'] ?? 'purchased_at');
        $sortDir = (string) ($_GET['sort_dir'] ?? 'DESC');

        $result = $this->couponService->myLectures($studentId, $page, $perPage, $search, $stageId, $sortBy, $sortDir);

        return ['success' => true, 'data' => $result['data'], 'meta' => $result['meta']];
    } 

        private function resolveStudentId(int $authUserId): int
    {
        $student = $this->studentService->getStudent($authUserId);

        if (empty($student['student_id'])) {
            throw new ForbiddenException();
        }

        return (int) $student['student_id'];
    }  

 
public function checkLectureAccess(int $lectureId): array
{
          $authUserId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $studentId = $this->resolveStudentId($authUserId);
    $result = $this->couponService->checkLectureAccess(
        $studentId,
        $lectureId
    );

    return [
        'success' => true,
        'data' => $result,
    ];
} 
 
 
 
 }
 