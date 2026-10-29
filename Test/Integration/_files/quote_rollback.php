<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$quote = $objectManager->create(Quote::class);
$objectManager->get(QuoteResource::class)->load($quote, 'pe_quote', 'reserved_order_id');
if ($quote->getId()) {
    $objectManager->get(QuoteResource::class)->delete($quote);
}
