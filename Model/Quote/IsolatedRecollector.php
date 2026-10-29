<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Quote;

use DmLab\PriceExplain\Model\Trace\Tracer;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\App\Emulation;

/**
 * Recollects quote totals on a private instance inside a rolled-back transaction; never saves.
 */
class IsolatedRecollector
{
    /**
     * @param QuoteFactory $quoteFactory fresh instance: the repository caches quotes per request
     * @param QuoteResource $quoteResource
     * @param Emulation $emulation
     * @param ResourceConnection $resourceConnection
     * @param Tracer $tracer
     */
    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly QuoteResource $quoteResource,
        private readonly Emulation $emulation,
        private readonly ResourceConnection $resourceConnection,
        private readonly Tracer $tracer
    ) {
    }

    /**
     * Recollect totals of a quote with collector tracing on.
     *
     * @param int $quoteId
     * @return Recollection
     * @throws NoSuchEntityException
     */
    public function recollect(int $quoteId): Recollection
    {
        $quote = $this->quoteFactory->create();
        // Not Quote::loadByIdWithoutStore(): its _afterLoad() saves recollected totals when trigger_recollect is set.
        $this->quoteResource->loadByIdWithoutStore($quote, $quoteId);
        if (!$quote->getId()) {
            throw new NoSuchEntityException(__('Quote with ID "%1" does not exist.', $quoteId));
        }
        $couponCode = $quote->getCouponCode();
        $couponCode = $couponCode === null || $couponCode === '' ? null : (string)$couponCode;

        $connection = $this->resourceConnection->getConnection('checkout');
        $this->emulation->startEnvironmentEmulation((int)$quote->getStoreId(), Area::AREA_FRONTEND, true);
        try {
            $connection->beginTransaction();
            try {
                $quote->setTriggerRecollect(0);
                $quote->afterLoad();
                $this->tracer->start();
                // Stored rates only: no live carrier calls.
                foreach ($quote->getAllAddresses() as $address) {
                    $address->setCollectShippingRates(false);
                }
                $quote->setTotalsCollectedFlag(false);
                $quote->collectTotals();
            } finally {
                $this->tracer->stop();
                $connection->rollBack();
            }
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        return new Recollection($quote, $this->tracer->getCollectorSteps(), $couponCode);
    }
}
