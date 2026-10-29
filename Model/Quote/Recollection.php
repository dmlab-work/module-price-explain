<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Quote;

use DmLab\PriceExplain\Model\Report\CollectorStep;
use Magento\Quote\Model\Quote;

/**
 * Result of an isolated totals recollection.
 */
class Recollection
{
    /**
     * @param Quote $quote recollected, unsaved instance
     * @param CollectorStep[] $collectorSteps
     * @param string|null $originalCouponCode coupon before core could clear an invalid one
     */
    public function __construct(
        private readonly Quote $quote,
        private readonly array $collectorSteps,
        private readonly ?string $originalCouponCode
    ) {
    }

    /**
     * Recollected quote.
     *
     * @return Quote
     */
    public function getQuote(): Quote
    {
        return $this->quote;
    }

    /**
     * Collector steps in execution order.
     *
     * @return CollectorStep[]
     */
    public function getCollectorSteps(): array
    {
        return $this->collectorSteps;
    }

    /**
     * Coupon code as stored on the quote.
     *
     * @return string|null
     */
    public function getOriginalCouponCode(): ?string
    {
        return $this->originalCouponCode;
    }
}
