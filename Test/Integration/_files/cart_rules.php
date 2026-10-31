<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Cart rules named "PE …"; coupon rules sort first so the stop-processing pair blocks only "PE Stop B".
 */
declare(strict_types=1);

use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Address;
use Magento\SalesRule\Model\Rule\Condition\Product;
use Magento\SalesRule\Model\Rule\Condition\Product\Found;
use Magento\SalesRule\Test\Fixture\Rule as RuleFixture;
use Magento\TestFramework\Fixture\DataFixtureFactory;
use Magento\TestFramework\Helper\Bootstrap;

$fixtures = Bootstrap::getObjectManager()->get(DataFixtureFactory::class);
$sku = static fn (string $value): array => [
    ['type' => Product::class, 'attribute' => 'sku', 'operator' => '==', 'value' => $value],
];

$rules = [
    [
        'name' => 'PE Coupon once',
        'coupon_code' => 'PE_ONCE',
        'uses_per_customer' => 1,
        'simple_action' => Rule::BY_FIXED_ACTION,
        'discount_amount' => 5,
        'sort_order' => 10,
    ],
    [
        'name' => 'PE Free shipping address',
        'coupon_code' => 'PE_FS_ADDR',
        'simple_free_shipping' => 2,
        'sort_order' => 11,
    ],
    [
        'name' => 'PE Free shipping items',
        'coupon_code' => 'PE_FS_ITEMS',
        'simple_free_shipping' => 1,
        'sort_order' => 12,
    ],
    [
        'name' => 'PE Subtotal 200+',
        'discount_amount' => 10,
        'sort_order' => 20,
        'conditions' => [
            ['type' => Address::class, 'attribute' => 'base_subtotal', 'operator' => '>=', 'value' => 200],
        ],
    ],
    [
        'name' => 'PE Step 3',
        'discount_amount' => 10,
        'discount_step' => 3,
        'sort_order' => 25,
        'actions' => $sku('pe-tier'),
    ],
    [
        'name' => 'PE Stop A',
        'discount_amount' => 5,
        'sort_order' => 30,
        'stop_rules_processing' => true,
        'actions' => $sku('pe-simple'),
    ],
    [
        'name' => 'PE Stop B',
        'discount_amount' => 5,
        'sort_order' => 40,
    ],
    [
        'name' => 'PE Found blue',
        'discount_amount' => 5,
        'sort_order' => 50,
        'conditions' => [[
            'type' => Found::class,
            'value' => 1,
            'aggregator' => 'all',
            'conditions' => [
                ['type' => Product::class, 'attribute' => 'sku', 'operator' => '==', 'value' => 'pe-conf-blue'],
            ],
        ]],
    ],
];
foreach ($rules as $rule) {
    $fixtures->create(RuleFixture::class)->apply($rule + [
        'simple_action' => Rule::BY_PERCENT_ACTION,
        'stop_rules_processing' => false,
    ]);
}
