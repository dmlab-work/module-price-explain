<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Model\Storefront\TokenService;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * The storefront endpoint returns the explainer's report for a valid token and refuses a used one.
 */
#[
    AppArea('frontend'),
    DbIsolation(false),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/catalog.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/cart_rules.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/tax_us_tx.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/quote.php'),
]
class StorefrontEndpointTest extends AbstractController
{
    private const URI = 'dmlab_price_explain/report/json';

    public function testValidTokenReturnsTheExplainerReport(): void
    {
        $quoteId = $this->quoteId();
        $this->post($this->issue($quoteId));

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertSame('no-store', $this->getResponse()->getHeader('Cache-Control')->getFieldValue());
        $body = json_decode((string)$this->getResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $expected = $this->_objectManager->create(ExplainerInterface::class)->explainQuote($quoteId)->toArray();
        self::assertSame(json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true), $body);
        self::assertSame($quoteId, $body['quote']['id']);
    }

    public function testUsedTokenIsForbidden(): void
    {
        $token = $this->issue($this->quoteId());
        $this->_objectManager->get(TokenService::class)->consume($token, 1);

        $this->post($token);

        self::assertSame(403, $this->getResponse()->getHttpResponseCode());
        self::assertSame(
            ['error' => 'forbidden'],
            json_decode((string)$this->getResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param int $quoteId
     * @return string
     */
    private function issue(int $quoteId): string
    {
        return $this->_objectManager->get(TokenService::class)->issue(TokenService::KIND_QUOTE, $quoteId, false, 1, 1);
    }

    /**
     * @param string $token
     * @return void
     */
    private function post(string $token): void
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST)->setPostValue(['token' => $token]);
        $this->dispatch(self::URI);
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
