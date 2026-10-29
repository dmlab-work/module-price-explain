<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Category "PE Sale" with a 20% catalog rule; simple, untaxed, tier-priced, special-priced and configurable products.
 */
declare(strict_types=1);

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogRule\Model\Indexer\IndexBuilder;
use Magento\CatalogRule\Test\Fixture\Rule as CatalogRuleFixture;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttributeFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Customer\Model\Group;
use Magento\TestFramework\Fixture\DataFixtureFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$fixtures = $objectManager->get(DataFixtureFactory::class);

$category = $fixtures->create(CategoryFixture::class)->apply(['name' => 'PE Sale', 'url_key' => 'pe-sale']);
$saleCategory = [(int)$category->getId()];

$fixtures->create(ProductFixture::class)->apply([
    'sku' => 'pe-simple',
    'name' => 'PE Simple',
    'price' => 100,
]);
$fixtures->create(ProductFixture::class)->apply([
    'sku' => 'pe-untaxed',
    'name' => 'PE Untaxed',
    'price' => 20,
    'tax_class_id' => '0',
]);
$fixtures->create(ProductFixture::class)->apply([
    'sku' => 'pe-tier',
    'name' => 'PE Tier',
    'price' => 50,
    'category_ids' => $saleCategory,
    'tier_prices' => [['customer_group_id' => Group::CUST_GROUP_ALL, 'qty' => 2, 'value' => 35]],
]);
$fixtures->create(ProductFixture::class)->apply([
    'sku' => 'pe-special',
    'name' => 'PE Special',
    'price' => 80,
    'special_price' => 60,
    'special_from_date' => date('Y-m-d', strtotime('+10 days')),
]);

$color = $fixtures->create(ConfigurableAttributeFixture::class)->apply([
    'attribute_code' => 'pe_color',
    'default_frontend_label' => 'PE Color',
    'options' => [['label' => 'Red', 'sort_order' => 0], ['label' => 'Blue', 'sort_order' => 1]],
]);
$fixtures->create(ProductFixture::class)->apply([
    'sku' => 'pe-conf-red',
    'name' => 'PE Configurable Red',
    'price' => 30,
    'visibility' => 1,
    'category_ids' => $saleCategory,
]);
$fixtures->create(ProductFixture::class)->apply([
    'sku' => 'pe-conf-blue',
    'name' => 'PE Configurable Blue',
    'price' => 35,
    'visibility' => 1,
    'category_ids' => $saleCategory,
]);
$fixtures->create(ConfigurableProductFixture::class)->apply([
    'sku' => 'pe-conf',
    'name' => 'PE Configurable',
    'category_ids' => $saleCategory,
    '_options' => [$color],
    '_links' => ['pe-conf-red', 'pe-conf-blue'],
]);

$fixtures->create(CatalogRuleFixture::class)->apply([
    'name' => 'PE Sale 20%',
    'discount_amount' => 20,
    'conditions' => [['attribute' => 'category_ids', 'value' => (string)$category->getId()]],
]);
$objectManager->get(IndexBuilder::class)->reindexFull();
