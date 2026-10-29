<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Plugin\Customer;

use Magento\Customer\Block\Adminhtml\Edit\Tab\Cart;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;

/**
 * Adds an "Explain Prices" link to each website cart grid of the customer's Shopping cart tab.
 *
 * The per-website grids are created in Tab\Carts::_prepareLayout, out of reach of layout XML;
 * the grid's buttons bar also follows its AJAX reloads (website filter).
 */
class CartExplainLinkPlugin
{
    public const RESOURCE = 'DmLab_PriceExplain::explain';

    /**
     * @param AuthorizationInterface $authorization
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * Append the link when the cart has items and the admin may explain prices.
     *
     * @param Cart $subject
     * @param string $result
     * @return string
     */
    public function afterGetMainButtonsHtml(Cart $subject, $result): string
    {
        $result = (string)$result;
        if (!$this->authorization->isAllowed(self::RESOURCE)) {
            return $result;
        }
        $quoteId = $this->quoteId($subject);
        if ($quoteId === null) {
            return $result;
        }
        $url = $subject->getUrl('dmlab_price_explain/report/view', ['quote_id' => $quoteId]);

        return $result . sprintf(
            '<a class="action-default scalable dmlab-price-explain" href="%s">%s</a>',
            $this->escaper->escapeUrl($url),
            $this->escaper->escapeHtml((string)__('Explain Prices'))
        );
    }

    /**
     * Quote of the grid (Tab\Cart::getQuote is protected), read from its item collection; null for an empty cart.
     *
     * @param Cart $subject
     * @return int|null
     */
    private function quoteId(Cart $subject): ?int
    {
        $collection = $subject->getCollection();
        if ($collection === null) {
            return null;
        }
        foreach ($collection as $item) {
            $quoteId = (int)$item->getQuoteId();
            if ($quoteId > 0) {
                return $quoteId;
            }
        }

        return null;
    }
}
