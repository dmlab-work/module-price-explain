<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Storefront;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Resolves the admin report input to a quote or order and its store, without loading the quote.
 */
class TargetResolver
{
    /**
     * @param QuoteResource $quoteResource
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly QuoteResource $quoteResource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Quote by ID.
     *
     * @param int $quoteId
     * @return ReportTarget
     * @throws NoSuchEntityException
     */
    public function quote(int $quoteId): ReportTarget
    {
        $connection = $this->quoteResource->getConnection();
        $storeId = $connection->fetchOne(
            $connection->select()
                ->from($this->quoteResource->getMainTable(), ['store_id'])
                ->where('entity_id = ?', $quoteId)
        );
        if ($storeId === false) {
            throw new NoSuchEntityException(__('Quote with ID "%1" does not exist.', $quoteId));
        }

        return new ReportTarget(TokenService::KIND_QUOTE, $quoteId, (int)$storeId, (string)__('Quote #%1', $quoteId));
    }

    /**
     * Order by entity ID.
     *
     * @param int $orderId
     * @return ReportTarget
     * @throws NoSuchEntityException
     */
    public function order(int $orderId): ReportTarget
    {
        return $this->fromOrder($this->orderRepository->get($orderId));
    }

    /**
     * Order by number; ambiguous across stores is an error.
     *
     * @param string $incrementId
     * @return ReportTarget
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function orderByIncrementId(string $incrementId): ReportTarget
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->create();
        $orders = array_values($this->orderRepository->getList($criteria)->getItems());
        if ($orders === []) {
            throw new NoSuchEntityException(__('Order "%1" does not exist.', $incrementId));
        }
        if (count($orders) > 1) {
            throw new LocalizedException(
                __(
                    'Order number "%1" exists in several stores; open the order and explain it from there.',
                    $incrementId
                )
            );
        }

        return $this->fromOrder($orders[0]);
    }

    /**
     * Target of a loaded order.
     *
     * @param OrderInterface $order
     * @return ReportTarget
     */
    private function fromOrder(OrderInterface $order): ReportTarget
    {
        return new ReportTarget(
            TokenService::KIND_ORDER,
            (int)$order->getEntityId(),
            (int)$order->getStoreId(),
            (string)__('Order #%1', $order->getIncrementId())
        );
    }
}
