<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Tax;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\TaxLine;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;

/**
 * Tax section: classes, the address rates were looked up for, applied rates per line and the config flags.
 * Amounts come from the recollected quote (quote currency); "no tax" is explained by repeating the rate lookup.
 */
class TaxExplainer
{
    public const SOURCE_FALLBACK = 'fallback';

    /**
     * @param Config $config
     * @param Calculation $calculation
     * @param TaxClassRepositoryInterface $taxClassRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param ItemLabel $itemLabel
     */
    public function __construct(
        private readonly Config $config,
        private readonly Calculation $calculation,
        private readonly TaxClassRepositoryInterface $taxClassRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ItemLabel $itemLabel
    ) {
    }

    /**
     * Explain the tax of the recollected quote.
     *
     * @param Quote $quote
     * @return TaxBreakdown
     */
    public function explain(Quote $quote): TaxBreakdown
    {
        $storeId = (int)$quote->getStoreId();
        $billing = $quote->getBillingAddress();
        $address = $quote->isVirtual() ? $billing : $quote->getShippingAddress();
        $basedOn = (string)$this->scopeConfig->getValue(
            Config::CONFIG_XML_PATH_BASED_ON,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $customerClassId = (int)$quote->getCustomerTaxClassId();
        $request = $this->calculation->getRateRequest(
            $address,
            $billing,
            $customerClassId,
            $storeId,
            $quote->getCustomerId()
        );

        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $taxed = $item->isChildrenCalculated() ? $item->getChildren() : [$item];
            foreach ($taxed as $line) {
                $lines[] = $this->itemLine($line, $request);
            }
        }
        if (!$quote->isVirtual()) {
            $lines[] = $this->shippingLine($address, $request, $storeId);
        }

        return new TaxBreakdown(
            $basedOn,
            (string)$this->config->getAlgorithm($storeId),
            $this->className($customerClassId),
            [
                'source' => $this->source($basedOn, $address, $billing),
                'country_id' => $request->getCountryId() ?: null,
                'region_id' => $request->getRegionId() ? (int)$request->getRegionId() : null,
                'postcode' => $request->getPostcode() ?: null,
            ],
            [
                'price_includes_tax' => (bool)$this->config->priceIncludesTax($storeId),
                'shipping_includes_tax' => (bool)$this->config->shippingPriceIncludesTax($storeId),
                'apply_after_discount' => (bool)$this->config->applyTaxAfterDiscount($storeId),
                'discount_tax' => (bool)$this->config->discountTax($storeId),
                'cross_border_trade_enabled' => (bool)$this->config->crossBorderTradeEnabled($storeId),
            ],
            $lines,
            $this->rates(array_values((array)$address->getAppliedTaxes()))
        );
    }

    /**
     * Tax of one item.
     *
     * @param AbstractItem $item
     * @param DataObject $request
     * @return TaxLine
     */
    private function itemLine(AbstractItem $item, DataObject $request): TaxLine
    {
        $classId = $this->productClassId($item);
        $amount = (float)$item->getTaxAmount();
        $rates = $this->rates((array)$item->getAppliedTaxes());

        return new TaxLine(
            TaxLine::TYPE_ITEM,
            (int)$item->getId(),
            $this->itemLabel->get($item),
            $this->className($classId),
            $amount,
            $rates,
            $amount == 0 ? $this->reason($rates, $classId, $request, false) : null
        );
    }

    /**
     * Tax of the shipping charge.
     *
     * @param Address $address
     * @param DataObject $request
     * @param int $storeId
     * @return TaxLine
     */
    private function shippingLine(Address $address, DataObject $request, int $storeId): TaxLine
    {
        $classId = (int)$this->config->getShippingTaxClass($storeId);
        $amount = (float)$address->getShippingTaxAmount();
        $applied = (array)$address->getItemsAppliedTaxes();
        $rates = $this->rates($applied[CommonTaxCollector::ITEM_CODE_SHIPPING] ?? []);
        $reason = null;
        if ($amount == 0) {
            $reason = (float)$address->getShippingAmount() == 0
                ? (string)__('No shipping charge.')
                : $this->reason($rates, $classId, $request, true);
        }

        return new TaxLine(
            TaxLine::TYPE_SHIPPING,
            null,
            TaxLine::TYPE_SHIPPING,
            $this->className($classId),
            $amount,
            $rates,
            $reason
        );
    }

    /**
     * Tax class core uses for the item: the child's for a configurable (ConfigurableProduct plugin on mapItem).
     *
     * @param AbstractItem $item
     * @return int
     */
    private function productClassId(AbstractItem $item): int
    {
        $children = $item->getHasChildren() ? $item->getChildren() : [];
        if ($item->getProduct()->getTypeId() === 'configurable' && $children) {
            return (int)$children[0]->getProduct()->getTaxClassId();
        }

        return (int)$item->getProduct()->getTaxClassId();
    }

    /**
     * Why an untaxed line has no tax, by repeating the rate lookup for its class.
     *
     * @param array[] $rates rates core applied
     * @param int $classId product or shipping tax class
     * @param DataObject $request rate request without a product class
     * @param bool $shipping
     * @return string
     */
    private function reason(array $rates, int $classId, DataObject $request, bool $shipping): string
    {
        $applied = array_filter($rates, static fn (array $rate): bool => $rate['percent'] > 0);
        if ($applied) {
            return (string)__('Tax rates apply but the taxable amount is zero.');
        }
        if ($classId === 0) {
            return $shipping
                ? (string)__('No shipping tax class is configured (%1).', Config::CONFIG_XML_PATH_SHIPPING_TAX_CLASS)
                : (string)__('The product tax class is "None".');
        }
        if (!$request->getCustomerClassId()) {
            return (string)__('The customer group has no tax class.');
        }
        if (!$request->getCountryId()) {
            return (string)__('No country to look up tax rates for.');
        }

        $lookup = clone $request;
        $lookup->setProductClassId($classId);
        $matched = [];
        foreach ($this->calculation->getAppliedRates($lookup) as $row) {
            foreach ($row['rates'] ?? [] as $rate) {
                $matched[] = $rate;
            }
        }
        if (!$matched) {
            return (string)__(
                'No tax rate matches %1 for product class "%2" and customer class "%3".',
                $this->location($request),
                $this->className($classId),
                $this->className((int)$request->getCustomerClassId())
            );
        }
        if (!array_filter($matched, static fn (array $rate): bool => (float)$rate['percent'] > 0)) {
            return (string)__(
                'A zero-rate tax rule applies: %1.',
                implode(', ', array_unique(array_column($matched, 'code')))
            );
        }

        return (string)__('Tax rates match (%1) but core applied none.', implode(', ', array_column($matched, 'code')));
    }

    /**
     * Applied tax rows (core's array format) flattened to rates; a combined row's amount is split by percent.
     *
     * @param array[] $applied
     * @return array[] each with code, title, percent, amount
     */
    private function rates(array $applied): array
    {
        $rates = [];
        foreach ($applied as $row) {
            $rowRates = $row['rates'] ?? [];
            $total = array_sum(array_map(static fn (array $rate): float => (float)$rate['percent'], $rowRates));
            foreach ($rowRates as $rate) {
                $percent = (float)$rate['percent'];
                $rates[] = [
                    'code' => (string)$rate['code'],
                    'title' => (string)$rate['title'],
                    'percent' => $percent,
                    'amount' => $total > 0 ? (float)$row['amount'] * $percent / $total : 0.0,
                ];
            }
        }

        return $rates;
    }

    /**
     * Which address the rates were looked up for: the configured one, origin, or a core fallback
     * (customer default address, the other quote address, tax/defaults/*).
     *
     * @param string $basedOn
     * @param Address $address
     * @param Address $billing
     * @return string
     */
    private function source(string $basedOn, Address $address, Address $billing): string
    {
        $used = match ($basedOn) {
            'shipping' => $address,
            'billing' => $billing,
            default => null,
        };
        if ($basedOn === 'origin' || ($used !== null && $used->getCountryId())) {
            return $basedOn;
        }

        return self::SOURCE_FALLBACK;
    }

    /**
     * "US, region 12, 90001".
     *
     * @param DataObject $request
     * @return string
     */
    private function location(DataObject $request): string
    {
        $parts = [(string)$request->getCountryId()];
        if ($request->getRegionId()) {
            $parts[] = (string)__('region %1', $request->getRegionId());
        }
        if ($request->getPostcode()) {
            $parts[] = (string)$request->getPostcode();
        }

        return implode(', ', $parts);
    }

    /**
     * Tax class name; null for "None", "#ID" for a deleted class.
     *
     * @param int $classId
     * @return string|null
     */
    private function className(int $classId): ?string
    {
        if ($classId === 0) {
            return null;
        }
        try {
            return (string)$this->taxClassRepository->get($classId)->getClassName();
        } catch (NoSuchEntityException) {
            return '#' . $classId;
        }
    }
}
