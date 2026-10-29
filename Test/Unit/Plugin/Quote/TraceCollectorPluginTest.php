<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Plugin\Quote;

use DmLab\PriceExplain\Model\Trace\CollectorSortOrders;
use DmLab\PriceExplain\Model\Trace\Tracer;
use DmLab\PriceExplain\Plugin\Quote\TraceCollectorPlugin;
use DmLab\PriceExplain\Test\Unit\Fixture\VendorTotal;
use DmLab\PriceExplain\Test\Unit\Fixture\VendorTotal\Interceptor;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Sales\Model\Config;
use Magento\SalesRule\Model\Quote\Discount;
use PHPUnit\Framework\TestCase;

class TraceCollectorPluginTest extends TestCase
{
    public function testPassesThroughWhenInactive(): void
    {
        $tracer = new Tracer();
        $total = $this->createMock(Total::class);
        $total->expects(self::never())->method('getAllTotalAmounts');
        $quote = $this->createStub(Quote::class);
        $assignment = $this->assignment('shipping');
        $calls = 0;
        $proceed = function ($q, $a, $t) use (&$calls, $quote, $assignment, $total) {
            self::assertSame([$quote, $assignment, $total], [$q, $a, $t]);
            $calls++;

            return 'result';
        };

        $result = $this->plugin($tracer)
            ->aroundCollect($this->collector(Discount::class, 'discount'), $proceed, $quote, $assignment, $total);

        self::assertSame('result', $result);
        self::assertSame(1, $calls);
        self::assertSame([], $tracer->getCollectorSteps());
    }

    public function testRecordsDeltaOfCoreCollector(): void
    {
        $tracer = new Tracer();
        $tracer->start();
        $total = $this->total(
            [['subtotal' => 100.0, 'discount' => 0.0], ['subtotal' => 100.0, 'discount' => -9.36, 'shipping' => 5.0]],
            [['subtotal' => 50.0], ['subtotal' => 50.0, 'discount' => -4.68]]
        );
        $collector = $this->collector(Discount::class, 'discount');

        $result = $this->plugin($tracer)->aroundCollect(
            $collector,
            fn () => $collector,
            $this->createStub(Quote::class),
            $this->assignment('shipping'),
            $total
        );

        self::assertSame($collector, $result);
        $steps = $tracer->getCollectorSteps();
        self::assertCount(1, $steps);
        self::assertSame('discount', $steps[0]->getCode());
        self::assertSame(Discount::class, $steps[0]->getClass());
        self::assertFalse($steps[0]->isThirdParty());
        self::assertSame('shipping', $steps[0]->getAddressType());
        self::assertEqualsWithDelta(['discount' => -9.36, 'shipping' => 5.0], $steps[0]->getDelta(), 0.0001);
        self::assertEqualsWithDelta(['discount' => -4.68], $steps[0]->getBaseDelta(), 0.0001);
        self::assertSame(300, $steps[0]->getSortOrder());
    }

    public function testRecordsRemovedAmountAndEmptyDelta(): void
    {
        $tracer = new Tracer();
        $tracer->start();
        $plugin = $this->plugin($tracer);
        $collector = $this->collector(Discount::class, 'discount');

        $plugin->aroundCollect(
            $collector,
            fn () => null,
            $this->createStub(Quote::class),
            $this->assignment('billing'),
            $this->total([['fee' => 3.0], []], [[], []])
        );
        $plugin->aroundCollect(
            $collector,
            fn () => null,
            $this->createStub(Quote::class),
            $this->assignment('billing'),
            $this->total([['subtotal' => 10.0], ['subtotal' => 10.0]], [[], []])
        );

        $steps = $tracer->getCollectorSteps();
        self::assertSame(['fee' => -3.0], $steps[0]->getDelta());
        self::assertSame([], $steps[1]->getDelta());
        self::assertSame('billing', $steps[1]->getAddressType());
    }

    public function testFlagsThirdPartyAndStripsInterceptor(): void
    {
        $tracer = new Tracer();
        $tracer->start();

        $this->plugin($tracer)->aroundCollect(
            $this->collector(Interceptor::class, 'vendor_fee'),
            fn () => null,
            $this->createStub(Quote::class),
            $this->assignment('shipping'),
            $this->total([[], ['vendor_fee' => 2.5]], [[], ['vendor_fee' => 2.5]])
        );

        $step = $tracer->getCollectorSteps()[0];
        self::assertSame('vendor_fee', $step->getCode());
        self::assertSame(VendorTotal::class, $step->getClass());
        self::assertTrue($step->isThirdParty());
        self::assertSame(['vendor_fee' => 2.5], $step->getDelta());
        self::assertNull($step->getSortOrder());
    }

    public function testRecordsGrandTotalData(): void
    {
        $tracer = new Tracer();
        $tracer->start();
        $total = new Total([], new Json());
        $total->setTotalAmount('subtotal', 100.0);
        $total->setBaseTotalAmount('subtotal', 50.0);
        $collector = $this->collector(Discount::class, 'grand_total');

        $this->plugin($tracer)->aroundCollect(
            $collector,
            function ($quote, $assignment, Total $total) {
                $total->setGrandTotal(105.0);
                $total->setBaseGrandTotal(52.5);
            },
            $this->createStub(Quote::class),
            $this->assignment('shipping'),
            $total
        );

        $step = $tracer->getCollectorSteps()[0];
        self::assertSame(['grand_total' => 105.0], $step->getDelta());
        self::assertSame(['grand_total' => 52.5], $step->getBaseDelta());
    }

    private function plugin(Tracer $tracer): TraceCollectorPlugin
    {
        $config = $this->createStub(Config::class);
        $config->method('getGroupTotals')->willReturn(['discount' => ['sort_order' => '300']]);

        return new TraceCollectorPlugin($tracer, new CollectorSortOrders($config));
    }

    /**
     * @param class-string<AbstractTotal> $class
     */
    private function collector(string $class, string $code): AbstractTotal
    {
        /** @var AbstractTotal $collector */
        $collector = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $collector->setCode($code);

        return $collector;
    }

    private function assignment(string $addressType): ShippingAssignmentInterface
    {
        /** @var Address $address */
        $address = (new \ReflectionClass(Address::class))->newInstanceWithoutConstructor();
        $address->setData('address_type', $addressType);
        $shipping = $this->createStub(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($address);
        $assignment = $this->createStub(ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);

        return $assignment;
    }

    /**
     * @param array{array, array} $amounts before/after
     * @param array{array, array} $baseAmounts before/after
     */
    private function total(array $amounts, array $baseAmounts): Total
    {
        $total = $this->createStub(Total::class);
        $total->method('getAllTotalAmounts')->willReturnOnConsecutiveCalls(...$amounts);
        $total->method('getAllBaseTotalAmounts')->willReturnOnConsecutiveCalls(...$baseAmounts);

        return $total;
    }
}
