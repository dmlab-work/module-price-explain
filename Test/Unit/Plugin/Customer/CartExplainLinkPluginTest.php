<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Plugin\Customer;

use DmLab\PriceExplain\Plugin\Customer\CartExplainLinkPlugin;
use Magento\Customer\Block\Adminhtml\Edit\Tab\Cart;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Collection;
use Magento\Framework\DataObject;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

class CartExplainLinkPluginTest extends TestCase
{
    private const FILTER_HTML = '<button>Search</button>';

    private function cart(?Collection $collection): Cart
    {
        $cart = $this->createStub(Cart::class);
        $cart->method('getCollection')->willReturn($collection);
        $cart->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params) => 'https://admin/' . $route . '/quote_id/' . $params['quote_id']
                . '/?a=1&b=2'
        );

        return $cart;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function items(array $rows): Collection
    {
        $collection = new Collection($this->createStub(EntityFactoryInterface::class));
        foreach ($rows as $row) {
            $collection->addItem(new DataObject($row));
        }

        return $collection;
    }

    private function plugin(bool $allowed): CartExplainLinkPlugin
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('DmLab_PriceExplain::explain')->willReturn($allowed);

        $escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback($escape);
        $escaper->method('escapeUrl')->willReturnCallback($escape);

        return new CartExplainLinkPlugin($authorization, $escaper);
    }

    public function testAppendsLinkToTheGridQuoteReport(): void
    {
        $cart = $this->cart($this->items([['item_id' => 1, 'quote_id' => 42], ['item_id' => 2, 'quote_id' => 42]]));

        $html = $this->plugin(true)->afterGetMainButtonsHtml($cart, self::FILTER_HTML);

        self::assertStringStartsWith(self::FILTER_HTML, $html);
        self::assertStringContainsString(
            'href="https://admin/dmlab_price_explain/report/view/quote_id/42/?a=1&amp;b=2"',
            $html
        );
        self::assertStringContainsString('>Explain Prices</a>', $html);
    }

    public function testNoLinkWithoutAcl(): void
    {
        $cart = $this->cart($this->items([['item_id' => 1, 'quote_id' => 42]]));

        self::assertSame(self::FILTER_HTML, $this->plugin(false)->afterGetMainButtonsHtml($cart, self::FILTER_HTML));
    }

    public function testNoLinkForEmptyCart(): void
    {
        self::assertSame(
            self::FILTER_HTML,
            $this->plugin(true)->afterGetMainButtonsHtml($this->cart($this->items([])), self::FILTER_HTML)
        );
    }

    public function testNoLinkWithoutCollection(): void
    {
        self::assertSame('', $this->plugin(true)->afterGetMainButtonsHtml($this->cart(null), ''));
    }
}
