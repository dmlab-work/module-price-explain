<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Quote;

use DmLab\PriceExplain\Model\Report\Totals;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Reads the totals of a recollected quote and, optionally, of the order placed from it.
 */
class TotalsReader
{
    /**
     * Totals of the quote; with the order's totals when an order is given.
     *
     * @param Quote $quote recollected
     * @param OrderInterface|null $order
     * @return Totals
     */
    public function read(Quote $quote, ?OrderInterface $order = null): Totals
    {
        $current = [
            Totals::SUBTOTAL => (float)$quote->getSubtotal(),
            Totals::GRAND_TOTAL => (float)$quote->getGrandTotal(),
        ];
        $base = [
            Totals::SUBTOTAL => (float)$quote->getBaseSubtotal(),
            Totals::GRAND_TOTAL => (float)$quote->getBaseGrandTotal(),
        ];
        foreach ($quote->getAllAddresses() as $address) {
            foreach ($this->addressAmounts($address) as $code => [$amount, $baseAmount]) {
                $current[$code] = ($current[$code] ?? 0.0) + $amount;
                $base[$code] = ($base[$code] ?? 0.0) + $baseAmount;
            }
        }
        $baseCurrency = $quote->getBaseCurrencyCode();

        return new Totals(
            $current,
            $base,
            $baseCurrency === null ? null : (string)$baseCurrency,
            $order === null ? null : [
                Totals::SUBTOTAL => (float)$order->getSubtotal(),
                Totals::DISCOUNT => (float)$order->getDiscountAmount(),
                Totals::SHIPPING => (float)$order->getShippingAmount(),
                Totals::TAX => (float)$order->getTaxAmount(),
                Totals::GRAND_TOTAL => (float)$order->getGrandTotal(),
            ],
            $order === null ? null : [
                Totals::SUBTOTAL => (float)$order->getBaseSubtotal(),
                Totals::DISCOUNT => (float)$order->getBaseDiscountAmount(),
                Totals::SHIPPING => (float)$order->getBaseShippingAmount(),
                Totals::TAX => (float)$order->getBaseTaxAmount(),
                Totals::GRAND_TOTAL => (float)$order->getBaseGrandTotal(),
            ]
        );
    }

    /**
     * Address discount, shipping and tax as code => [amount, base amount].
     *
     * @param DataObject $address
     * @return array<string, float[]>
     */
    private function addressAmounts(DataObject $address): array
    {
        return [
            Totals::DISCOUNT => [(float)$address->getDiscountAmount(), (float)$address->getBaseDiscountAmount()],
            Totals::SHIPPING => [(float)$address->getShippingAmount(), (float)$address->getBaseShippingAmount()],
            Totals::TAX => [(float)$address->getTaxAmount(), (float)$address->getBaseTaxAmount()],
        ];
    }
}
