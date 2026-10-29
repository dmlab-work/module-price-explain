<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Storefront;

/**
 * Quote or order an admin report explains, with the store it runs in.
 */
class ReportTarget
{
    /**
     * @param string $kind TokenService::KIND_QUOTE or KIND_ORDER
     * @param int $id quote ID or order entity ID
     * @param int $storeId
     * @param string $label "Quote #14" / "Order #000000003"
     */
    public function __construct(
        private readonly string $kind,
        private readonly int $id,
        private readonly int $storeId,
        private readonly string $label
    ) {
    }

    /**
     * Quote or order.
     *
     * @return string
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Quote ID or order entity ID.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Store view of the quote or order.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * Human-readable name for the page title.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }
}
