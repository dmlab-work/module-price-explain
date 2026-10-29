<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\Calculation\RuleFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$rule = $objectManager->get(RuleFactory::class)->create()->load('PE US-TX', 'code');
if ($rule->getId()) {
    $rule->delete();
}
$rate = $objectManager->get(RateFactory::class)->create()->load('PE US-TX', 'code');
if ($rate->getId()) {
    $rate->delete();
}
