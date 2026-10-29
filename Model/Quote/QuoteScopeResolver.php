<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Quote;

use DmLab\PriceExplain\Model\Report\QuoteScope;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

/**
 * Resolves the store view, website and customer group names and the customer of a quote.
 */
class QuoteScopeResolver
{
    /**
     * @param GroupRepositoryInterface $groupRepository
     */
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    /**
     * Names and customer of the quote; a missing store, website or group yields a null name.
     *
     * @param Quote $quote
     * @return QuoteScope
     */
    public function resolve(Quote $quote): QuoteScope
    {
        $store = $quote->getStore();
        $website = $store->getWebsite();
        $customerId = (int)$quote->getCustomerId();
        $name = trim($quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname());
        $email = (string)$quote->getCustomerEmail();

        return new QuoteScope(
            $this->text($store->getName()),
            $website ? $this->text($website->getName()) : null,
            $this->groupName((int)$quote->getCustomerGroupId()),
            $customerId > 0 ? $customerId : null,
            $email !== '' ? $email : null,
            $name !== '' ? $name : null
        );
    }

    /**
     * Customer group code, null when the group no longer exists.
     *
     * @param int $groupId
     * @return string|null
     */
    private function groupName(int $groupId): ?string
    {
        try {
            return $this->text($this->groupRepository->getById($groupId)->getCode());
        } catch (LocalizedException) {
            return null;
        }
    }

    /**
     * Non-empty string or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private function text(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }
}
