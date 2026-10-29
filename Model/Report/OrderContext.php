<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * The order an order report explains.
 */
class OrderContext
{
    /**
     * @param string $incrementId order number
     * @param int $entityId
     * @param string|null $createdAt
     * @param string|null $status
     */
    public function __construct(
        private readonly string $incrementId,
        private readonly int $entityId,
        private readonly ?string $createdAt = null,
        private readonly ?string $status = null
    ) {
    }

    /**
     * Order number.
     *
     * @return string
     */
    public function getIncrementId(): string
    {
        return $this->incrementId;
    }

    /**
     * Order entity ID.
     *
     * @return int
     */
    public function getEntityId(): int
    {
        return $this->entityId;
    }

    /**
     * Placement date.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Order status code.
     *
     * @return string|null
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * JSON-ready order block.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'increment_id' => $this->incrementId,
            'entity_id' => $this->entityId,
            'created_at' => $this->createdAt,
            'status' => $this->status,
        ];
    }
}
