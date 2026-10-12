<?php

namespace App\Contracts;

interface StudentRepositoryInterface
{
    public function find(int $id): ?array;


    public function all(array $filters, int $limit, int $offset, string $sort, string $order): array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function delete(int $id): int;

    public function findByQrCode(string $qrCode): ?array;

    /**
     * Batch version of findByQrCode — resolves many QR codes in one query.
     * Used by bulk attendance to avoid N+1 lookups.
     *
     * @param string[] $qrCodes
     * @return array<int, array<string, mixed>> rows, each containing at least id, qr_code
     */
    public function findManyByQrCodes(array $qrCodes): array;

    public function incrementTotalLectures(int $studentId): void;

    public function findByUserId(int $userId): ?array;
    public function updateDeviceIdentifier(int $studentId, string $deviceIdentifier): int;
    public function clearDeviceIdentifier(int $studentId): int;
}
 