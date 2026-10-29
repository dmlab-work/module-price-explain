<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Model\Explainer;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Order report of an order placed from the fixture quote: identity, ordered totals, no false mismatch.
 */
#[
    AppArea('frontend'),
    DbIsolation(false),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/catalog.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/cart_rules.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/tax_us_tx.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/quote.php'),
]
class ExplainerOrderTest extends TestCase
{
    /**
     * @var OrderInterface|null placed by the test, deleted in tearDown
     */
    private ?OrderInterface $order = null;

    protected function tearDown(): void
    {
        if ($this->order === null) {
            return;
        }
        $objectManager = Bootstrap::getObjectManager();
        $registry = $objectManager->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        $objectManager->get(OrderRepositoryInterface::class)->delete($this->order);
        $registry->unregister('isSecureArea');
        $resource = $objectManager->get(ResourceConnection::class);
        $resource->getConnection()->delete(
            $resource->getTableName('inventory_reservation'),
            ['sku LIKE ?' => 'pe-%']
        );
    }

    public function testOrderReportCarriesOrderIdentityAndMatchingTotals(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $quote = $objectManager->create(Quote::class);
        $objectManager->get(QuoteResource::class)->load($quote, 'pe_quote', 'reserved_order_id');
        $quote->getPayment()->setMethod('checkmo');
        $objectManager->get(QuoteResource::class)->save($quote);
        $orderId = (int)$objectManager->get(CartManagementInterface::class)->placeOrder((int)$quote->getId());
        $this->order = $objectManager->get(OrderRepositoryInterface::class)->get($orderId);

        $report = $objectManager->create(ExplainerInterface::class)->explainOrderById($orderId);
        $data = $report->toArray();

        self::assertSame($this->order->getIncrementId(), $data['order']['increment_id']);
        self::assertSame($orderId, $data['order']['entity_id']);
        self::assertSame((int)$quote->getId(), $data['quote']['id']);
        self::assertSame(
            number_format((float)$this->order->getGrandTotal(), 2, '.', ''),
            $data['totals']['ordered']['grand_total']
        );
        self::assertSame(
            number_format((float)$this->order->getDiscountAmount(), 2, '.', ''),
            $data['totals']['ordered']['discount']
        );
        self::assertSame([], $data['totals']['differences']);
        $codes = array_map(static fn (Warning $w): string => $w->getCode(), $report->getWarnings());
        self::assertNotContains(Explainer::WARNING_ORDER_TOTALS, $codes);
        self::assertNotContains(Explainer::WARNING_ORDER_RULES, $codes);
    }
}
