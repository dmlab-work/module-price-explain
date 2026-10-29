<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Plugin\Sales;

use DmLab\PriceExplain\Plugin\Sales\OrderViewExplainButtonPlugin;
use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class OrderViewExplainButtonPluginTest extends TestCase
{
    /**
     * @var array<string, array>
     */
    private array $buttons = [];

    private function view(?Order $order): View
    {
        $view = $this->createStub(View::class);
        $view->method('getOrder')->willReturn($order);
        $view->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params) => 'https://admin/' . $route . '/order_id/' . $params['order_id']
        );
        $view->method('addButton')->willReturnCallback(function (string $id, array $data) use ($view) {
            $this->buttons[$id] = $data;
            return $view;
        });

        return $view;
    }

    private function order(?int $quoteId): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(15);
        $order->method('getQuoteId')->willReturn($quoteId);

        return $order;
    }

    private function plugin(bool $allowed): OrderViewExplainButtonPlugin
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('DmLab_PriceExplain::explain')->willReturn($allowed);

        return new OrderViewExplainButtonPlugin($authorization);
    }

    public function testAddsButtonPointingToTheOrderReport(): void
    {
        self::assertNull($this->plugin(true)->beforeSetLayout($this->view($this->order(42))));

        self::assertArrayHasKey('dmlab_price_explain', $this->buttons);
        $button = $this->buttons['dmlab_price_explain'];
        self::assertSame('Explain Prices', (string)$button['label']);
        self::assertSame(
            "setLocation('https://admin/dmlab_price_explain/report/view/order_id/15')",
            $button['onclick']
        );
    }

    public function testNoButtonWithoutAcl(): void
    {
        $this->plugin(false)->beforeSetLayout($this->view($this->order(42)));

        self::assertSame([], $this->buttons);
    }

    public function testNoButtonWhenOrderHasNoQuote(): void
    {
        $this->plugin(true)->beforeSetLayout($this->view($this->order(null)));

        self::assertSame([], $this->buttons);
    }

    public function testNoButtonWithoutOrder(): void
    {
        $this->plugin(true)->beforeSetLayout($this->view(null));

        self::assertSame([], $this->buttons);
    }
}
