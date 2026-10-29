<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogRule\Model\Indexer\IndexBuilder;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as CatalogRuleCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$registry = $objectManager->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

$productRepository = $objectManager->get(ProductRepositoryInterface::class);
foreach (['pe-conf', 'pe-conf-red', 'pe-conf-blue', 'pe-simple', 'pe-untaxed', 'pe-tier', 'pe-special'] as $sku) {
    try {
        $productRepository->deleteById($sku);
    } catch (NoSuchEntityException) {
    }
}
try {
    $objectManager->get(ProductAttributeRepositoryInterface::class)->deleteById('pe_color');
} catch (NoSuchEntityException) {
}
foreach ($objectManager->get(CategoryCollectionFactory::class)->create()
    ->addAttributeToFilter('name', 'PE Sale') as $category) {
    $category->delete();
}
foreach ($objectManager->get(CatalogRuleCollectionFactory::class)->create()
    ->addFieldToFilter('name', 'PE Sale 20%') as $rule) {
    $rule->delete();
}
$objectManager->get(IndexBuilder::class)->reindexFull();

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
