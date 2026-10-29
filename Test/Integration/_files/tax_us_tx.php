<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * 8.25% for US-TX: retail customers (class 3), taxable goods (class 2).
 */
declare(strict_types=1);

use Magento\Directory\Model\RegionFactory;
use Magento\Tax\Test\Fixture\TaxRate as TaxRateFixture;
use Magento\Tax\Test\Fixture\TaxRule as TaxRuleFixture;
use Magento\TestFramework\Fixture\DataFixtureFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$fixtures = $objectManager->get(DataFixtureFactory::class);
$texas = $objectManager->get(RegionFactory::class)->create()->loadByCode('TX', 'US');

$rate = $fixtures->create(TaxRateFixture::class)->apply([
    'code' => 'PE US-TX',
    'rate' => 8.25,
    'tax_region_id' => (int)$texas->getId(),
]);
$fixtures->create(TaxRuleFixture::class)->apply([
    'code' => 'PE US-TX',
    'customer_tax_class_ids' => [3],
    'product_tax_class_ids' => [2],
    'tax_rate_ids' => [(int)$rate->getId()],
]);
