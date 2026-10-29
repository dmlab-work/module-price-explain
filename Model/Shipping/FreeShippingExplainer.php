<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Shipping;

use DmLab\PriceExplain\Model\Report\FormatsAmounts;
use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Store\Model\ScopeInterface;

/**
 * Checks the free-shipping flags core set against what the selected carrier charges with the stored rate.
 */
class FreeShippingExplainer
{
    use FormatsAmounts;

    public const WARNING_CODE = 'free_shipping_not_effective';
    public const XML_FREE_ACTIVE = 'carriers/freeshipping/active';
    public const XML_FREE_SUBTOTAL = 'carriers/freeshipping/free_shipping_subtotal';

    /**
     * Carrier code => which flags it honours (OfflineShipping carriers).
     */
    private const SEMANTICS = [
        'flatrate' => FreeShipping::SEMANTICS_ITEMS,
        'tablerate' => FreeShipping::SEMANTICS_QTY,
        'freeshipping' => FreeShipping::SEMANTICS_ADDRESS,
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Explain free shipping of the recollected quote; free-shipping rule verdicts get the outcome.
     *
     * @param Quote $quote recollected quote
     * @param RuleVerdict[] $verdicts
     * @return FreeShippingExplanation
     */
    public function explain(Quote $quote, array $verdicts): FreeShippingExplanation
    {
        if ($quote->isVirtual()) {
            return new FreeShippingExplanation(null, $verdicts);
        }
        $address = $quote->getShippingAddress();
        $rules = [];
        foreach ($verdicts as $verdict) {
            if ($verdict->getFreeShippingMode() !== null) {
                $rules[] = [
                    'id' => $verdict->getRuleId(),
                    'name' => $verdict->getName(),
                    'mode' => $verdict->getFreeShippingMode(),
                ];
            }
        }
        $method = (string)$address->getShippingMethod();
        $rate = $method === '' ? null : $address->getShippingRateByCode($method);
        $carrier = $rate ? (string)$rate->getCarrier() : (string)strtok($method, '_');
        $semantics = self::SEMANTICS[$carrier] ?? FreeShipping::SEMANTICS_UNKNOWN;
        [$items, $freeBoxes, $packageQty] = $this->boxes($address, $method, $semantics);
        $addressFlag = (bool)$address->getFreeShipping();
        if ($rules === [] && !$addressFlag && $items === []) {
            return new FreeShippingExplanation(null, $verdicts);
        }

        $storeId = (int)$quote->getStoreId();
        $price = $rate ? (float)$rate->getPrice() : null;
        $scope = ScopeInterface::SCOPE_STORE;
        $freeCarrierEnabled = $this->scopeConfig->isSetFlag(self::XML_FREE_ACTIVE, $scope, $storeId);
        $subtotal = $this->scopeConfig->getValue(self::XML_FREE_SUBTOTAL, $scope, $storeId);
        $rateUpdatedAt = $rate && $rate->getUpdatedAt() ? (string)$rate->getUpdatedAt() : null;
        $quoteUpdatedAt = $quote->getUpdatedAt() ? (string)$quote->getUpdatedAt() : null;

        $code = $this->verdictCode($price, $semantics, $addressFlag || $items !== [], $freeBoxes, $packageQty);
        $section = new FreeShipping(
            $code,
            $this->message(
                $code,
                $rate ? ((string)$rate->getCarrierTitle() ?: $carrier) : $method,
                $price,
                $freeBoxes,
                $packageQty,
                $freeCarrierEnabled,
                $rateUpdatedAt,
                $quoteUpdatedAt
            ),
            $rules,
            $addressFlag,
            $items,
            $freeBoxes,
            $packageQty,
            $method === '' ? null : $method,
            $price,
            $semantics,
            $freeCarrierEnabled,
            $subtotal === null || $subtotal === '' ? null : (float)$subtotal,
            $rateUpdatedAt,
            $quoteUpdatedAt
        );

        return new FreeShippingExplanation(
            $section,
            array_map(
                static fn (RuleVerdict $v): RuleVerdict => $v->getFreeShippingMode() !== null
                    ? $v->withFreeShippingOutcome($code)
                    : $v,
                $verdicts
            ),
            $section->isEffective() ? [] : [new Warning(
                self::WARNING_CODE,
                (string)__('Free shipping is not effective: %1', $section->getMessage()),
                [
                    'verdict' => $code,
                    'method' => $section->getMethod(),
                    'rule_ids' => array_column($rules, 'id'),
                ]
            )]
        );
    }

    /**
     * Flagged items and box counts as the offline carriers count them.
     *
     * @param Address $address
     * @param string $method selected method; an item flag set for another method does not count
     * @param string $semantics FreeShipping::SEMANTICS_*
     * @return array{0: array[], 1: float, 2: float}
     */
    private function boxes(Address $address, string $method, string $semantics): array
    {
        // Tablerate::collectRates subtracts a numeric flag from the item qty and falls back to the address flag.
        $byQty = $semantics === FreeShipping::SEMANTICS_QTY;
        $items = [];
        $freeBoxes = $packageQty = 0.0;
        foreach ($address->getAllItems() as $item) {
            if ($item->getParentItem() || $this->isVirtual($item)) {
                continue;
            }
            $qty = (float)$item->getQty();
            if ($item->getHasChildren() && $item->isShipSeparately()) {
                foreach ($item->getChildren() as $child) {
                    if ($this->isVirtual($child)) {
                        continue;
                    }
                    $childQty = $qty * (float)$child->getQty();
                    $packageQty += $childQty;
                    if ($child->getFreeShipping()) {
                        $free = $byQty ? $qty * ((float)$child->getQty() - (int)$child->getFreeShipping()) : $childQty;
                        $freeBoxes += $free;
                        $items[] = $this->itemRow($child, $free);
                    }
                }
                continue;
            }
            $packageQty += $qty;
            $itemMethod = (string)$item->getFreeShippingMethod();
            if ($itemMethod !== '' && $itemMethod !== $method) {
                continue;
            }
            $flag = $item->getFreeShipping() ?: ($byQty ? $address->getFreeShipping() : null);
            if (!$flag) {
                continue;
            }
            $free = $byQty && is_numeric($flag) ? $qty - (float)$flag : $qty;
            $freeBoxes += $free;
            if ($item->getFreeShipping()) {
                $items[] = $this->itemRow($item, $free);
            }
        }

        return [$items, $freeBoxes, $packageQty];
    }

    /**
     * Flagged item row.
     *
     * @param AbstractItem $item
     * @param float $qty
     * @return array
     */
    private function itemRow(AbstractItem $item, float $qty): array
    {
        return ['item_id' => (int)$item->getId(), 'sku' => (string)$item->getSku(), 'qty' => $qty];
    }

    /**
     * Whether the item is virtual.
     *
     * @param AbstractItem $item
     * @return bool
     */
    private function isVirtual(AbstractItem $item): bool
    {
        return (bool)$item->getProduct()?->isVirtual();
    }

    /**
     * Verdict code from the charged price and the carrier semantics.
     *
     * @param float|null $price
     * @param string $semantics
     * @param bool $flagged address or an item carries a free-shipping flag
     * @param float $freeBoxes
     * @param float $packageQty
     * @return string
     */
    private function verdictCode(
        ?float $price,
        string $semantics,
        bool $flagged,
        float $freeBoxes,
        float $packageQty
    ): string {
        $allBoxesFree = $packageQty > 0 && $freeBoxes >= $packageQty;

        return match (true) {
            $price === null => FreeShipping::NO_METHOD,
            round($price, 4) == 0 => FreeShipping::EFFECTIVE,
            !$flagged => FreeShipping::NOT_GRANTED,
            $semantics === FreeShipping::SEMANTICS_UNKNOWN => FreeShipping::UNKNOWN_CARRIER,
            $allBoxesFree, $semantics === FreeShipping::SEMANTICS_ADDRESS => FreeShipping::STALE_RATE,
            $freeBoxes > 0, $semantics === FreeShipping::SEMANTICS_QTY => FreeShipping::PARTIAL,
            default => FreeShipping::NOT_HONOURED,
        };
    }

    /**
     * Human-readable verdict.
     *
     * @param string $code
     * @param string $carrier carrier title, or the selected method without a stored rate ("" when none)
     * @param float|null $price
     * @param float $freeBoxes
     * @param float $packageQty
     * @param bool $freeCarrierEnabled
     * @param string|null $rateUpdatedAt
     * @param string|null $quoteUpdatedAt
     * @return string
     */
    private function message(
        string $code,
        string $carrier,
        ?float $price,
        float $freeBoxes,
        float $packageQty,
        bool $freeCarrierEnabled,
        ?string $rateUpdatedAt,
        ?string $quoteUpdatedAt
    ): string {
        $charged = (string)$this->formatAmount($price, PriceCurrencyInterface::DEFAULT_PRECISION);

        return (string)match ($code) {
            FreeShipping::EFFECTIVE => __('%1 charges 0.00.', $carrier),
            FreeShipping::NO_METHOD => $carrier === ''
                ? __('No shipping method is selected yet.')
                : __(
                    '%1 is selected but has no stored rate; re-select the shipping method to collect rates.',
                    $carrier
                ),
            FreeShipping::NOT_GRANTED => __(
                'A free-shipping rule applied, but core set no free-shipping flag; %1 charges %2.',
                $carrier,
                $charged
            ),
            FreeShipping::UNKNOWN_CARRIER => __(
                'Third-party carrier %1 charges %2 although free shipping is set; '
                . 'it decides on its own whether to honour the flag.',
                $carrier,
                $charged
            ),
            FreeShipping::STALE_RATE => __(
                '%1 still charges %2 although free shipping is set: the stored rate (saved %3, quote updated %4) '
                . 'was collected before the rule applied. Re-select the shipping method to recollect rates.',
                $carrier,
                $charged,
                $rateUpdatedAt ?? '?',
                $quoteUpdatedAt ?? '?'
            ),
            FreeShipping::PARTIAL => __(
                '%1 of %2 boxes ship free; %3 charges %4 for the rest.',
                (string)$this->formatQty($freeBoxes),
                (string)$this->formatQty($packageQty),
                $carrier,
                $charged
            ),
            default => $freeCarrierEnabled
                ? __(
                    '%1 ignores shipment-level free shipping and charges %2; '
                    . 'select Free Shipping or set the rule to matching items only.',
                    $carrier,
                    $charged
                )
                : __(
                    '%1 ignores shipment-level free shipping and charges %2; '
                    . 'enable Free Shipping or set the rule to matching items only.',
                    $carrier,
                    $charged
                ),
        };
    }
}
