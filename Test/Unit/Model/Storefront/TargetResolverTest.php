<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Storefront;

use DmLab\PriceExplain\Model\Storefront\ReportTarget;
use DmLab\PriceExplain\Model\Storefront\TargetResolver;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

class TargetResolverTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: mixed}> captured addFilter() arguments
     */
    private array $filters = [];

    /**
     * @param string|false $storeId fetchOne() result
     * @param array<string, mixed> $where captured where() arguments
     * @return QuoteResource
     */
    private function quoteResource(string|false $storeId, array &$where = []): QuoteResource
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value) use (&$where, $select) {
            $where[$condition] = $value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($storeId);
        $resource = $this->createStub(QuoteResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote');

        return $resource;
    }

    private function order(int $id, int $storeId, string $incrementId): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getEntityId')->willReturn($id);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }

    /**
     * @param OrderInterface[] $found
     * @param OrderInterface|null $byId
     * @return TargetResolver
     */
    private function resolver(array $found = [], ?OrderInterface $byId = null): TargetResolver
    {
        $results = $this->createStub(OrderSearchResultInterface::class);
        $results->method('getItems')->willReturn($found);
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('getList')->willReturn($results);
        if ($byId !== null) {
            $orders->method('get')->willReturn($byId);
        } else {
            $orders->method('get')->willThrowException(new NoSuchEntityException(__('No order.')));
        }
        $criteria = $this->createStub(SearchCriteriaBuilder::class);
        $criteria->method('addFilter')->willReturnCallback(function (string $field, $value) use ($criteria) {
            $this->filters[] = [$field, $value];

            return $criteria;
        });
        $criteria->method('create')->willReturn($this->createStub(SearchCriteria::class));

        return new TargetResolver($this->quoteResource('2'), $orders, $criteria);
    }

    private static function assertTarget(string $kind, int $id, int $store, string $label, ReportTarget $t): void
    {
        self::assertSame([$kind, $id, $store, $label], [$t->getKind(), $t->getId(), $t->getStoreId(), $t->getLabel()]);
    }

    public function testQuoteReadsItsStoreWithoutLoadingTheQuote(): void
    {
        $where = [];
        $resolver = new TargetResolver(
            $this->quoteResource('2', $where),
            $this->createStub(OrderRepositoryInterface::class),
            $this->createStub(SearchCriteriaBuilder::class)
        );

        self::assertTarget('quote', 14, 2, 'Quote #14', $resolver->quote(14));
        self::assertSame(['entity_id = ?' => 14], $where);
    }

    public function testMissingQuote(): void
    {
        $resolver = new TargetResolver(
            $this->quoteResource(false),
            $this->createStub(OrderRepositoryInterface::class),
            $this->createStub(SearchCriteriaBuilder::class)
        );

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Quote with ID "99" does not exist.');
        $resolver->quote(99);
    }

    public function testOrderById(): void
    {
        self::assertTarget(
            'order',
            7,
            3,
            'Order #000000007',
            $this->resolver([], $this->order(7, 3, '000000007'))->order(7)
        );
    }

    public function testMissingOrderById(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->resolver()->order(7);
    }

    public function testOrderByIncrementId(): void
    {
        self::assertTarget(
            'order',
            12,
            1,
            'Order #000000012',
            $this->resolver([$this->order(12, 1, '000000012')])->orderByIncrementId('000000012')
        );
        self::assertSame([['increment_id', '000000012']], $this->filters);
    }

    public function testMissingOrderByIncrementId(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Order "X" does not exist.');
        $this->resolver()->orderByIncrementId('X');
    }

    public function testIncrementIdInSeveralStoresIsAmbiguous(): void
    {
        $resolver = $this->resolver([$this->order(1, 1, '1'), $this->order(2, 2, '1')]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('exists in several stores');
        $resolver->orderByIncrementId('1');
    }
}
