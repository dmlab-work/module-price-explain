<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Quote;

use DmLab\PriceExplain\Model\Quote\IsolatedRecollector;
use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Trace\Tracer;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IsolatedRecollectorTest extends TestCase
{
    /** @var Quote&MockObject */
    private MockObject $quote;
    /** @var AdapterInterface&MockObject */
    private MockObject $connection;
    /** @var Emulation&MockObject */
    private MockObject $emulation;
    /** @var QuoteResource&MockObject */
    private MockObject $quoteResource;
    /** @var Tracer */
    private Tracer $tracer;
    /** @var string[] */
    private array $log = [];

    protected function setUp(): void
    {
        $this->tracer = new Tracer();
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                ['loadByIdWithoutStore', 'afterLoad', 'getId', 'getStoreId', 'getAllAddresses', 'collectTotals', 'save']
            )
            ->getMock();
        $this->quote->expects(self::never())->method('save');
        $this->quote->expects(self::never())->method('loadByIdWithoutStore');
        $this->quote->method('afterLoad')->willReturnCallback(function () {
            self::assertSame(0, $this->quote->getTriggerRecollect());
            $this->log[] = 'after-load';
            return $this->quote;
        });
        $this->quoteResource = $this->createMock(QuoteResource::class);
        $this->quoteResource->expects(self::never())->method('save');
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->expects(self::never())->method('commit');
        $this->connection->method('beginTransaction')->willReturnCallback(function () {
            $this->log[] = 'begin';
            return $this->connection;
        });
        $this->connection->method('rollBack')->willReturnCallback(function () {
            $this->log[] = 'rollback';
            return $this->connection;
        });
        $this->emulation = $this->createMock(Emulation::class);
        $this->emulation->expects(self::atMost(1))->method('stopEnvironmentEmulation')->willReturnCallback(function () {
            $this->log[] = 'stop-emulation';
            return $this->emulation;
        });
    }

    public function testRecollectsInsideRolledBackTransaction(): void
    {
        $shipping = $this->address();
        $billing = $this->address();
        $this->givenQuote(42, 3, 'SPRING', [$shipping, $billing]);
        $this->emulation->expects(self::once())->method('startEnvironmentEmulation')
            ->with(3, Area::AREA_FRONTEND, true);
        $step = new CollectorStep('subtotal', 'Magento\\X', false, 'shipping', ['subtotal' => 10.0]);
        $this->quote->expects(self::once())->method('collectTotals')->willReturnCallback(function () use ($step) {
            self::assertTrue($this->tracer->isActive());
            self::assertFalse($this->quote->getTotalsCollectedFlag());
            $this->tracer->addCollectorStep($step);
            $this->quote->setCouponCode('');
            $this->log[] = 'collect';
            return $this->quote;
        });

        $result = $this->recollector()->recollect(42);

        self::assertSame(['begin', 'after-load', 'collect', 'rollback', 'stop-emulation'], $this->log);
        self::assertSame($this->quote, $result->getQuote());
        self::assertSame([$step], $result->getCollectorSteps());
        self::assertSame('SPRING', $result->getOriginalCouponCode());
        self::assertFalse($shipping->getCollectShippingRates());
        self::assertFalse($billing->getCollectShippingRates());
        self::assertFalse($this->tracer->isActive());
    }

    public function testRollsBackAndStopsTracingOnException(): void
    {
        $this->givenQuote(42, 1, null, []);
        $this->quote->method('collectTotals')->willThrowException(new \RuntimeException('collector failed'));

        try {
            $this->recollector()->recollect(42);
            self::fail('Exception expected');
        } catch (\RuntimeException $e) {
            self::assertSame('collector failed', $e->getMessage());
        }

        self::assertSame(['begin', 'after-load', 'rollback', 'stop-emulation'], $this->log);
        self::assertFalse($this->tracer->isActive());
    }

    public function testEmptyCouponIsNull(): void
    {
        $this->givenQuote(42, 1, '', []);

        self::assertNull($this->recollector()->recollect(42)->getOriginalCouponCode());
    }

    public function testTriggerRecollectFlagDoesNotSaveTheQuote(): void
    {
        $this->givenQuote(42, 1, 'SPRING', [], ['trigger_recollect' => 1]);
        $this->quote->expects(self::once())->method('collectTotals')->willReturnSelf();

        self::assertSame('SPRING', $this->recollector()->recollect(42)->getOriginalCouponCode());
        self::assertSame(['begin', 'after-load', 'rollback', 'stop-emulation'], $this->log);
    }

    public function testMissingQuoteThrowsWithoutTouchingDatabase(): void
    {
        $this->givenQuote(null, 1, null, []);
        $this->connection->expects(self::never())->method('beginTransaction');
        $this->emulation->expects(self::never())->method('startEnvironmentEmulation');
        $this->quote->expects(self::never())->method('afterLoad');

        $this->expectException(NoSuchEntityException::class);
        $this->recollector()->recollect(404);
    }

    public function testDoesNotDependOnCartRepository(): void
    {
        foreach ((new \ReflectionClass(IsolatedRecollector::class))->getConstructor()->getParameters() as $parameter) {
            $type = (string)$parameter->getType();
            self::assertFalse(is_a($type, CartRepositoryInterface::class, true), $type);
        }
    }

    /**
     * @param Address[] $addresses
     * @param array $data raw row data
     */
    private function givenQuote(?int $id, int $storeId, ?string $coupon, array $addresses, array $data = []): void
    {
        $this->quoteResource->expects(self::once())->method('loadByIdWithoutStore')
            ->with($this->quote)
            ->willReturnCallback(function (Quote $quote) use ($data) {
                $quote->addData($data);
                return $this->quoteResource;
            });
        $this->quote->method('getId')->willReturn($id);
        $this->quote->method('getStoreId')->willReturn($storeId);
        $this->quote->method('getAllAddresses')->willReturn($addresses);
        $this->quote->setCouponCode($coupon);
        $this->quote->setTotalsCollectedFlag(true);
    }

    private function address(): Address
    {
        /** @var Address $address */
        $address = (new \ReflectionClass(Address::class))->newInstanceWithoutConstructor();
        $address->setCollectShippingRates(true);

        return $address;
    }

    private function recollector(): IsolatedRecollector
    {
        $factory = $this->createStub(QuoteFactory::class);
        $factory->method('create')->willReturn($this->quote);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturnCallback(function (string $name) {
            self::assertSame('checkout', $name);
            return $this->connection;
        });

        return new IsolatedRecollector($factory, $this->quoteResource, $this->emulation, $resource, $this->tracer);
    }
}
