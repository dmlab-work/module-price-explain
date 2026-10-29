<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Plugin\Sales;

use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Block\Adminhtml\Order\View;

/**
 * Adds "Explain Prices" to the admin order view toolbar.
 */
class OrderViewExplainButtonPlugin
{
    public const RESOURCE = 'DmLab_PriceExplain::explain';
    public const BUTTON_ID = 'dmlab_price_explain';

    /**
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * Register the button before the toolbar is pushed in _prepareLayout.
     *
     * @param View $subject
     * @return null
     */
    public function beforeSetLayout(View $subject)
    {
        $order = $subject->getOrder();
        if ($order === null || !$order->getQuoteId() || !$this->authorization->isAllowed(self::RESOURCE)) {
            return null;
        }
        $url = $subject->getUrl('dmlab_price_explain/report/view', ['order_id' => $order->getId()]);
        $subject->addButton(self::BUTTON_ID, [
            'label' => __('Explain Prices'),
            'class' => 'dmlab-price-explain',
            'onclick' => sprintf("setLocation('%s')", $url),
        ]);

        return null;
    }
}
