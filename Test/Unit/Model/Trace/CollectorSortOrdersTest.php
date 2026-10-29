<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Trace;

use DmLab\PriceExplain\Model\Trace\CollectorSortOrders;
use Magento\Sales\Model\Config;
use PHPUnit\Framework\TestCase;

class CollectorSortOrdersTest extends TestCase
{
    public function testReadsQuoteTotalsOnce(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('getGroupTotals')->with('quote', 'totals')->willReturn([
            'subtotal' => ['instance' => 'Subtotal', 'sort_order' => '100'],
            'discount' => ['instance' => 'Discount', 'sort_order' => 300],
            'vendor_fee' => ['instance' => 'Fee'],
            'broken' => 'x',
        ]);
        $sortOrders = new CollectorSortOrders($config);

        self::assertSame(100, $sortOrders->get('subtotal'));
        self::assertSame(300, $sortOrders->get('discount'));
        self::assertNull($sortOrders->get('vendor_fee'));
        self::assertNull($sortOrders->get('broken'));
        self::assertNull($sortOrders->get('unknown'));
    }

    public function testNoTotalsConfigured(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getGroupTotals')->willReturn(null);

        self::assertNull((new CollectorSortOrders($config))->get('subtotal'));
    }
}
