<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Report;

use DmLab\PriceExplain\Model\Report\Totals;
use PHPUnit\Framework\TestCase;

class TotalsTest extends TestCase
{
    public function testQuoteTotalsFillMissingCodesAndHaveNoOrderedColumn(): void
    {
        $totals = new Totals(['subtotal' => 100.0, 'grand_total' => 95.004], ['subtotal' => 50.0], 'EUR');

        self::assertSame(
            ['subtotal' => 100.0, 'discount' => 0.0, 'shipping' => 0.0, 'tax' => 0.0, 'grand_total' => 95.004],
            $totals->getCurrent()
        );
        self::assertNull($totals->getOrdered());
        self::assertNull($totals->getBaseOrdered());
        self::assertSame([], $totals->getDifferences(2));
        self::assertSame(
            [
                'base_currency' => 'EUR',
                'labels' => [
                    'subtotal' => 'Subtotal',
                    'discount' => 'Discount',
                    'shipping' => 'Shipping',
                    'tax' => 'Tax',
                    'grand_total' => 'Grand Total',
                ],
                'current' => [
                    'subtotal' => '100.00',
                    'discount' => '0.00',
                    'shipping' => '0.00',
                    'tax' => '0.00',
                    'grand_total' => '95.00',
                ],
                'base_current' => [
                    'subtotal' => '50.00',
                    'discount' => '0.00',
                    'shipping' => '0.00',
                    'tax' => '0.00',
                    'grand_total' => '0.00',
                ],
                'ordered' => null,
                'base_ordered' => null,
                'differences' => [],
            ],
            $totals->toArray()
        );
    }

    public function testDifferencesCompareAtPrecision(): void
    {
        $totals = new Totals(
            ['subtotal' => 100.0, 'discount' => -10.0, 'tax' => 8.0, 'grand_total' => 98.0],
            [],
            'USD',
            ['subtotal' => 100.0, 'discount' => -15.0, 'tax' => 8.004, 'grand_total' => 93.0],
            ['subtotal' => 100.0]
        );

        self::assertSame(['discount', 'grand_total'], $totals->getDifferences(2));
        self::assertSame(['discount', 'tax', 'grand_total'], $totals->getDifferences(3));
        self::assertSame('-15.00', $totals->toArray()['ordered']['discount']);
        self::assertSame('100.00', $totals->toArray()['base_ordered']['subtotal']);
        self::assertSame('0.00', $totals->toArray()['base_ordered']['grand_total']);
        self::assertSame(['discount', 'grand_total'], $totals->toArray()['differences']);
    }

    public function testNegativeZeroIsFormattedAsZero(): void
    {
        $totals = new Totals(['discount' => -0.0], [], null, ['discount' => 0.0]);

        self::assertSame('0.00', $totals->toArray()['current']['discount']);
        self::assertSame([], $totals->getDifferences(2));
        self::assertSame(['discount' => 0.0], array_intersect_key($totals->getBaseOrdered(), ['discount' => 1]));
    }
}
