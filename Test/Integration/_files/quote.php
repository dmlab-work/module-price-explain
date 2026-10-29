<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Guest quote "pe_quote" shipped to US-TX by Flat Rate: pe-simple ×1, pe-conf (Red) ×1.
 * Requires catalog.php, cart_rules.php and tax_us_tx.php.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
$products = $objectManager->get(ProductRepositoryInterface::class);
$color = $objectManager->get(ProductAttributeRepositoryInterface::class)->get('pe_color');
$texas = $objectManager->get(RegionFactory::class)->create()->loadByCode('TX', 'US');

$quote = $objectManager->get(QuoteFactory::class)->create();
$quote->setStoreId(1)
    ->setIsActive(true)
    ->setIsMultiShipping(0)
    ->setReservedOrderId('pe_quote')
    ->setCustomerIsGuest(true)
    ->setCustomerEmail('pe-guest@example.com');
$quote->addProduct($products->get('pe-simple'), 1);
$quote->addProduct($products->get('pe-conf'), new DataObject([
    'qty' => 1,
    'super_attribute' => [(int)$color->getAttributeId() => (int)$color->getSource()->getOptionId('Red')],
]));

$address = [
    'firstname' => 'Pat',
    'lastname' => 'Explain',
    'street' => '1 Main St',
    'city' => 'Austin',
    'region_id' => (int)$texas->getId(),
    'postcode' => '73301',
    'country_id' => 'US',
    'telephone' => '5125550100',
    'email' => 'pe-guest@example.com',
];
$quote->getBillingAddress()->addData($address);
$quote->getShippingAddress()->addData($address)
    ->setCollectShippingRates(true)
    ->setShippingMethod('flatrate_flatrate');
$quote->collectTotals();
$objectManager->get(CartRepositoryInterface::class)->save($quote);
