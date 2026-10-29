<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Controller\Adminhtml\Report\View;
use DmLab\PriceExplain\Model\Storefront\TokenService;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\View\LayoutInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The admin report page points the browser at the storefront endpoint of the quote's store with a valid token.
 */
#[
    DbIsolation(false),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/catalog.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/cart_rules.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/tax_us_tx.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/quote.php'),
]
class AdminReportPageTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = View::ADMIN_RESOURCE;

    /**
     * @var string
     */
    protected $uri = 'backend/dmlab_price_explain/report/view';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_GET;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getRequest()->setParam('quote_id', (string)$this->quoteId());
    }

    public function testShellGetsStorefrontEndpointAndToken(): void
    {
        $quoteId = $this->quoteId();
        $this->dispatch($this->uri . '/quote_id/' . $quoteId);

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        $block = $this->_objectManager->get(LayoutInterface::class)->getBlock(View::BLOCK_NAME);
        $store = $this->_objectManager->get(StoreManagerInterface::class)->getStore(1);
        $endpoint = (string)$block->getData('endpoint_url');
        self::assertStringStartsWith($store->getBaseUrl(), $endpoint);
        self::assertStringContainsString(View::ENDPOINT_ROUTE, $endpoint);
        self::assertStringNotContainsString('/backend/', $endpoint);

        $payload = $this->_objectManager->get(TokenService::class)->consume((string)$block->getData('token'), 1);
        self::assertSame([TokenService::KIND_QUOTE, $quoteId], [$payload->getKind(), $payload->getId()]);
    }

    /**
     * @return int
     */
    private function quoteId(): int
    {
        $quote = $this->_objectManager->create(Quote::class);
        $this->_objectManager->get(QuoteResource::class)->load($quote, 'pe_quote', 'reserved_order_id');

        return (int)$quote->getId();
    }
}
