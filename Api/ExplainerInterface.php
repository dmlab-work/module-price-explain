<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Api;

use DmLab\PriceExplain\Model\Report\Report;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Explains why a quote or an order costs what it costs; read-only, one explanation per request.
 *
 * @api
 */
interface ExplainerInterface
{
    /**
     * Explain a quote.
     *
     * @param int $quoteId
     * @param bool $includeAllRules also list cart rules of other websites
     * @return Report
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function explainQuote(int $quoteId, bool $includeAllRules = false): Report;

    /**
     * Explain an order by its number, using the order's quote and current rules and prices.
     *
     * @param string $incrementId
     * @param bool $includeAllRules
     * @return Report
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function explainOrder(string $incrementId, bool $includeAllRules = false): Report;

    /**
     * Explain an order by its entity ID, using the order's quote and current rules and prices.
     *
     * @param int $orderId
     * @param bool $includeAllRules
     * @return Report
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function explainOrderById(int $orderId, bool $includeAllRules = false): Report;
}
