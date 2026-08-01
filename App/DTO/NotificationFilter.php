<?php

namespace App\DTO;

/**
 * Encapsulates every optional search/filter parameter for listing notifications.
 * Keeps the repository method signature stable as new filters are a dded.
 */
 
class NotificationFilter
{
    public ?string $keyword;
     public ?string $targetType;
    public ?int $stageId;
    public ?bool $isActive;
    public ?bool $onlyDeleted;
    public ?bool $onlyScheduled;
    public ?bool $onlySent;
    public ?string $dateFrom;
    public ?string $dateTo;
    public ?int $createdBy;
    public string $sortBy;
    public string $sortDirection;

    public function __construct(
        ?string $keyword = null,
        ?string $targetType = null,
        ?int $stageId = null,
        ?bool $isActive = null,
        ?bool $onlyDeleted = null,
        ?bool $onlyScheduled = null,
        ?bool $onlySent = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $createdBy = null,
        string $sortBy = 'created_at',
        string $sortDirection = 'DESC'
    ) {
        $this->keyword = $keyword;
        $this->targetType = $targetType;
        $this->stageId = $stageId;
        $this->isActive = $isActive;
        $this->onlyDeleted = $onlyDeleted;
        $this->onlyScheduled = $onlyScheduled;
        $this->onlySent = $onlySent;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->createdBy = $createdBy;
        $this->sortBy = $sortBy;
        $this->sortDirection = $sortDirection;
    }
  

    public static function fromArray(array $query): self
    {
        $allowedSort = ['created_at', 'updated_at', 'scheduled_at', 'headline'];
        $sortBy = in_array($query['sort_by'] ?? '', $allowedSort, true) ? $query['sort_by'] : 'created_at';
        $sortDirection = strtoupper($query['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        return new self(
            keyword: isset($query['q']) ? trim((string) $query['q']) : null,
            targetType: $query['target_type'] ?? null,
            stageId: isset($query['stage_id']) ? (int) $query['stage_id'] : null,
            isActive: isset($query['is_active']) ? (bool) $query['is_active'] : null,
            onlyDeleted: isset($query['deleted']) ? (bool) $query['deleted'] : null,
            onlyScheduled: isset($query['scheduled']) ? (bool) $query['scheduled'] : null,
            onlySent: isset($query['sent']) ? (bool) $query['sent'] : null,
            dateFrom: $query['date_from'] ?? null,
            dateTo: $query['date_to'] ?? null,
            createdBy: isset($query['created_by']) ? (int) $query['created_by'] : null,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        ); 
    }
}