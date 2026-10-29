<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\TestFramework\Helper\Bootstrap;

foreach (Bootstrap::getObjectManager()->get(CollectionFactory::class)->create()
    ->addFieldToFilter('name', ['like' => 'PE %']) as $rule) {
    $rule->delete();
}
