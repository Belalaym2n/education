<?php

namespace App\Models;

/**
 * Stage
 *
 * Clean data model representing an education "stage" (e.g. grade level).
 * Maps directly from a `stages` database row. Contains no business logic —
 * business rules live in App\Services\StageService, per the architecture.
 */
class Stage
{
    public int $id;
    public string $name;
    public int $orderNumber;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $deletedAt;

    public function __construct(array $row)
    {
        $this->id          = (int) $row['id'];
        $this->name        = (string) $row['name'];
        $this->orderNumber = (int) ($row['order_number'] ?? 0);
        $this->createdAt   = $row['created_at'] ?? null;
        $this->updatedAt   = $row['updated_at'] ?? null;
        $this->deletedAt   = $row['deleted_at'] ?? null;
    }

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    /**
     * @param array<int, array> $rows
     * @return array<int, array>
     */
    public static function collection(array $rows): array
    {
        return array_map(
            static fn(array $row): array => (new self($row))->toArray(),
            $rows
        );
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'order_number' => $this->orderNumber,
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
            'deleted_at'   => $this->deletedAt,
        ];
    }
}