<?php

namespace App\Services;

use App\Contracts\StudentRepositoryInterface;
use App\Exceptions\AppException;
use App\Exceptions\DeviceMismatchException;
use App\Core\Logger;

class DeviceBindingService
{
    private const MAX_DEVICE_ID_LENGTH = 191;

    private StudentRepositoryInterface $studentRepo;
    private Logger $logger;

    public function __construct(StudentRepositoryInterface $studentRepo, Logger $logger)
    {
        $this->studentRepo = $studentRepo;
        $this->logger = $logger;
    }

    /**
     * Called at LOGIN time only. Not used for ordinary authenticated requests.
     *
     * - null stored device  -> bind the incoming device, allow.
     * - same device         -> allow.
     * - different device    -> reject (DeviceMismatchException).
     * - missing device id   -> reject (student auth requires it).
     */
    public function checkAndBindOnLogin(int $studentId, ?string $requestDeviceId): void
    {
        $requestDeviceId = $this->sanitize($requestDeviceId);

        if ($requestDeviceId === null) {
            throw new AppException(
                'Device identifier is required to log in.',
                422,
                'DEVICE_ID_REQUIRED'
            );
        }

        $student = $this->studentRepo->findByUserId($studentId) ?? $this->studentRepo->find($studentId);
        $stored = $student['device_identifier'] ?? null;

        if ($stored === null) {
            $this->studentRepo->updateDeviceIdentifier((int) ($student['id'] ?? $studentId), $requestDeviceId);
            $this->logger->info('Device bound at login', ['student_id' => $studentId]);
            return;
        }

        if (!hash_equals($stored, $requestDeviceId)) {
            $this->logger->info('Device mismatch at login', ['student_id' => $studentId]);
            throw new DeviceMismatchException();
        }

        // Same device — nothing to do.
    }

    /**
     * Called on every authenticated request for STUDENT role only
     * (invoked from AuthMiddleware). Read-only — never binds here;
     * binding only ever happens at login, per spec.
     */
    public function verifyForRequest(int $studentUserId, ?string $requestDeviceId): void
    {
        $requestDeviceId = $this->sanitize($requestDeviceId);

        if ($requestDeviceId === null) {
            throw new AppException('Device identifier is required.', 422, 'DEVICE_ID_REQUIRED');
        }

        $student = $this->studentRepo->findByUserId($studentUserId);

        // No student profile / no device bound yet -> nothing to enforce
        // (e.g. race with a login that hasn't completed). Fail safe = allow,
        // login is the only place that establishes the binding.
        if ($student === null || empty($student['device_identifier'])) {
            return;
        }

        if (!hash_equals($student['device_identifier'], $requestDeviceId)) {
            throw new DeviceMismatchException();
        }
    }

    public function resetDevice(int $studentId): void
    {
        $this->studentRepo->clearDeviceIdentifier($studentId);
        $this->logger->info('Device binding reset by admin', ['student_id' => $studentId]);
    }

    private function sanitize(?string $deviceId): ?string
    {
        if ($deviceId === null) return null;
        $deviceId = trim($deviceId);
        if ($deviceId === '' || mb_strlen($deviceId) > self::MAX_DEVICE_ID_LENGTH) {
            return null;
        }
        return $deviceId;
    }
}