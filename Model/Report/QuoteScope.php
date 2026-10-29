<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Names behind the quote's store, website and customer group, and who the customer is.
 */
class QuoteScope
{
    /**
     * @param string|null $storeName store view name
     * @param string|null $websiteName
     * @param string|null $customerGroupName null when the group no longer exists
     * @param int|null $customerId null for a guest
     * @param string|null $customerEmail
     * @param string|null $customerName
     */
    public function __construct(
        private readonly ?string $storeName,
        private readonly ?string $websiteName,
        private readonly ?string $customerGroupName,
        private readonly ?int $customerId = null,
        private readonly ?string $customerEmail = null,
        private readonly ?string $customerName = null
    ) {
    }

    /**
     * Store view name.
     *
     * @return string|null
     */
    public function getStoreName(): ?string
    {
        return $this->storeName;
    }

    /**
     * Website name.
     *
     * @return string|null
     */
    public function getWebsiteName(): ?string
    {
        return $this->websiteName;
    }

    /**
     * Customer group name.
     *
     * @return string|null
     */
    public function getCustomerGroupName(): ?string
    {
        return $this->customerGroupName;
    }

    /**
     * Customer ID, null for a guest.
     *
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * Whether the quote belongs to no customer account.
     *
     * @return bool
     */
    public function isGuest(): bool
    {
        return $this->customerId === null;
    }

    /**
     * Customer email.
     *
     * @return string|null
     */
    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    /**
     * Customer full name.
     *
     * @return string|null
     */
    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    /**
     * JSON-ready customer block.
     *
     * @return array<string, mixed>
     */
    public function customerToArray(): array
    {
        return [
            'id' => $this->customerId,
            'guest' => $this->isGuest(),
            'email' => $this->customerEmail,
            'name' => $this->customerName,
        ];
    }
}
