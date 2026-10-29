<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Quote;

use DmLab\PriceExplain\Model\Quote\TotalsReader;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TotalsReaderTest extends TestCase
{
    public function testSumsAddressAmountsOfTheQuote(): void
    {
        $totals = (new TotalsReader())->read($this->quote());

        self::assertSame(
            ['subtotal' => 100.0, 'discount' => -10.0, 'shipping' => 5.0, 'tax' => 8.0, 'grand_total' => 103.0],
            $totals->getCurrent()
        );
        self::assertSame(
            ['subtotal' => 50.0, 'discount' => -5.0, 'shipping' => 2.5, 'tax' => 4.0, 'grand_total' => 51.5],
            $totals->getBaseCurrent()
        );
        self::assertSame('USD', $totals->getBaseCurrency());
        self::assertNull($totals->getOrdered());
    }

    public function testReadsOrderedAmounts(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getSubtotal')->willReturn(100.0);
        $order->method('getDiscountAmount')->willReturn(-15.0);
        $order->method('getShippingAmount')->willReturn(5.0);
        $order->method('getTaxAmount')->willReturn(8.0);
        $order->method('getGrandTotal')->willReturn(98.0);
        $order->method('getBaseSubtotal')->willReturn(50.0);
        $order->method('getBaseDiscountAmount')->willReturn(-7.5);
        $order->method('getBaseShippingAmount')->willReturn(2.5);
        $order->method('getBaseTaxAmount')->willReturn(4.0);
        $order->method('getBaseGrandTotal')->willReturn(49.0);

        $totals = (new TotalsReader())->read($this->quote(), $order);

        self::assertSame(
            ['subtotal' => 100.0, 'discount' => -15.0, 'shipping' => 5.0, 'tax' => 8.0, 'grand_total' => 98.0],
            $totals->getOrdered()
        );
        self::assertSame(
            ['subtotal' => 50.0, 'discount' => -7.5, 'shipping' => 2.5, 'tax' => 4.0, 'grand_total' => 49.0],
            $totals->getBaseOrdered()
        );
        self::assertSame(['discount', 'grand_total'], $totals->getDifferences(2));
    }

    public function testMissingBaseCurrencyIsNull(): void
    {
        $quote = $this->quote();
        $quote->unsetData('base_currency_code');

        self::assertNull((new TotalsReader())->read($quote)->getBaseCurrency());
    }

    private function quote(): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllAddresses'])
            ->getMock();
        $quote->method('getAllAddresses')->willReturn([
            new DataObject(['discount_amount' => 0.0, 'shipping_amount' => 0.0]),
            new DataObject([
                'discount_amount' => -10.0,
                'base_discount_amount' => -5.0,
                'shipping_amount' => 5.0,
                'base_shipping_amount' => 2.5,
                'tax_amount' => 8.0,
                'base_tax_amount' => 4.0,
            ]),
        ]);
        $quote->setData([
            'subtotal' => 100.0,
            'base_subtotal' => 50.0,
            'grand_total' => 103.0,
            'base_grand_total' => 51.5,
            'base_currency_code' => 'USD',
        ]);

        return $quote;
    }
}
