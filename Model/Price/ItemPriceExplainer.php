<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Price;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as CatalogRuleCollectionFactory;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Api\Data\WebsiteInterface;

/**
 * Rebuilds Type\Price::getFinalPrice layer by layer for every visible quote item and compares the result
 * with the price core calculated. Amounts are in the base currency, like product prices.
 */
class ItemPriceExplainer
{
    public const WARNING_CODE = 'price_delta';
    public const EVENT = 'catalog_product_get_final_price';

    private const PRECISION = 4;

    /**
     * @param CatalogRuleResource $catalogRuleResource
     * @param CatalogRuleCollectionFactory $catalogRuleCollectionFactory
     * @param TimezoneInterface $timezone
     * @param EventConfig $eventConfig
     * @param ItemLabel $itemLabel
     */
    public function __construct(
        private readonly CatalogRuleResource $catalogRuleResource,
        private readonly CatalogRuleCollectionFactory $catalogRuleCollectionFactory,
        private readonly TimezoneInterface $timezone,
        private readonly EventConfig $eventConfig,
        private readonly ItemLabel $itemLabel
    ) {
    }

    /**
     * Explain the price of every visible item of the recollected quote.
     *
     * @param Quote $quote
     * @return PricesExplanation
     */
    public function explain(Quote $quote): PricesExplanation
    {
        $items = [];
        $warnings = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $itemPrice = $this->explainItem($quote, $item);
            if ($itemPrice->getUnexplainedDelta() !== null) {
                $warnings[] = new Warning(
                    self::WARNING_CODE,
                    (string)__(
                        'Item %1: core price %2 differs from the computed final price %3; '
                        . 'a third-party observer or plugin may change the final price.',
                        $itemPrice->getLabel(),
                        $itemPrice->getItemPrice(),
                        $itemPrice->getFinal()
                    ),
                    [
                        'item_id' => $itemPrice->getItemId(),
                        'delta' => $itemPrice->getUnexplainedDelta(),
                        'suspects' => $itemPrice->getSuspects(),
                    ]
                );
            }
            $items[] = $itemPrice;
        }

        return new PricesExplanation($items, $warnings);
    }

    /**
     * Price layers of one item.
     *
     * @param Quote $quote
     * @param Item $item
     * @return ItemPrice
     */
    private function explainItem(Quote $quote, Item $item): ItemPrice
    {
        /** @var Product $product */
        $product = $item->getProduct();
        $priced = $this->pricedProduct($item);
        $qty = (float)$item->getQty();
        // The tax collector replaces price with the tax-exclusive one; this keeps core's final price.
        $itemPrice = (float)($item->getBaseTaxCalculationPrice() ?? $item->getPrice());
        $customPrice = $item->getCustomPrice() !== null ? (float)$item->getBaseCalculationPrice() : null;

        if ($item->isChildrenCalculated() || $this->hasOwnPriceModel($priced)) {
            return new ItemPrice(
                (int)$item->getId(),
                (string)$item->getSku(),
                (string)$item->getName(),
                $qty,
                (int)$product->getId(),
                [],
                null,
                $itemPrice,
                $customPrice,
                null,
                [],
                $this->itemLabel->get($item)
            );
        }

        $layers = [
            PriceLayer::CODE_BASE => new PriceLayer(PriceLayer::CODE_BASE, (float)$priced->getPrice()),
            PriceLayer::CODE_SPECIAL => $this->specialLayer($priced),
            PriceLayer::CODE_TIER => $this->tierLayer($priced, $qty),
            PriceLayer::CODE_CATALOG_RULE => $this->catalogRuleLayer($quote, $priced),
        ];
        $winner = $this->winner($layers);
        $layers[$winner] = $this->markWon($layers[$winner]);
        $final = (float)$layers[$winner]->getValue();

        $options = $this->optionsPrice($product, $final);
        if ($options !== null) {
            $layers[PriceLayer::CODE_OPTIONS] = new PriceLayer(PriceLayer::CODE_OPTIONS, $options);
            $final += $options;
        }
        $final = max(0.0, $final);

        // A custom price replaces the final price, so it is not a delta.
        $delta = $customPrice === null ? round($itemPrice - $final, self::PRECISION) : 0.0;
        $delta = $delta == 0 ? null : $delta;

        return new ItemPrice(
            (int)$item->getId(),
            (string)$item->getSku(),
            (string)$item->getName(),
            $qty,
            (int)$priced->getId(),
            array_values($layers),
            $final,
            $itemPrice,
            $customPrice,
            $delta,
            $delta === null ? [] : $this->suspects(),
            $this->itemLabel->get($item)
        );
    }

    /**
     * Product whose price attributes are used: the simple child for a configurable.
     *
     * @param Item $item
     * @return Product
     */
    private function pricedProduct(Item $item): Product
    {
        $option = $item->getOptionByCode('simple_product');
        if ($option && $option->getProduct()) {
            return $option->getProduct();
        }

        return $item->getProduct();
    }

    /**
     * Whether the product has its own price model on top of Type\Price.
     *
     * Fixed bundles (percent special price, selections) and downloadables with separately priced links.
     *
     * @param Product $product
     * @return bool
     */
    private function hasOwnPriceModel(Product $product): bool
    {
        return match ((string)$product->getTypeId()) {
            'bundle' => true,
            'downloadable' => (bool)$product->getLinksPurchasedSeparately(),
            default => false,
        };
    }

    /**
     * Special price with its dates; inactive outside them (same date check as core).
     *
     * @param Product $product
     * @return PriceLayer
     */
    private function specialLayer(Product $product): PriceLayer
    {
        $special = $product->getSpecialPrice();
        $from = $product->getSpecialFromDate();
        $to = $product->getSpecialToDate();
        $details = ['from' => $from ?: null, 'to' => $to ?: null];
        if ($special === null || $special === '' || $special === false) {
            return new PriceLayer(PriceLayer::CODE_SPECIAL, null, false, false, $details);
        }
        $applied = $product->getPriceModel()->calculateSpecialPrice(
            PHP_FLOAT_MAX,
            $special,
            $from,
            $to,
            WebsiteInterface::ADMIN_CODE
        );

        return new PriceLayer(PriceLayer::CODE_SPECIAL, (float)$special, $applied < PHP_FLOAT_MAX, false, $details);
    }

    /**
     * Tier/group prices of the customer group with the one applicable to the qty.
     *
     * @param Product $product
     * @param float $qty
     * @return PriceLayer
     */
    private function tierLayer(Product $product, float $qty): PriceLayer
    {
        $tiers = [];
        foreach ((array)$product->getTierPrice() as $tier) {
            $tiers[] = [
                'qty' => (float)$tier['price_qty'],
                'price' => (float)$tier['website_price'],
                'customer_group' => (int)$tier['cust_group'],
            ];
        }
        $details = ['qty' => $qty, 'tiers' => $tiers];
        if (!$tiers) {
            return new PriceLayer(PriceLayer::CODE_TIER, null, false, false, $details);
        }

        $applicable = $product->getTierPrice($qty);
        if (!is_numeric($applicable) || (float)$applicable >= (float)$product->getPrice()) {
            return new PriceLayer(PriceLayer::CODE_TIER, null, false, false, $details);
        }

        return new PriceLayer(PriceLayer::CODE_TIER, (float)$applicable, true, false, $details);
    }

    /**
     * Indexed catalog rule price for the quote's date, website and group, with the rules that produced it.
     *
     * @param Quote $quote
     * @param Product $product
     * @return PriceLayer
     */
    private function catalogRuleLayer(Quote $quote, Product $product): PriceLayer
    {
        $storeId = $quote->getStoreId();
        $websiteId = (int)$quote->getStore()->getWebsiteId();
        $groupId = (int)$quote->getCustomerGroupId();
        $productId = (int)$product->getId();

        $rulePrice = $this->catalogRuleResource->getRulePrice(
            $this->timezone->scopeDate($storeId),
            $websiteId,
            $groupId,
            $productId
        );
        $rows = $this->catalogRuleResource->getRulesFromProduct(
            $this->timezone->scopeTimeStamp($storeId),
            $websiteId,
            $groupId,
            $productId
        );
        $rules = $this->ruleNames($rows);
        if ($rulePrice === false || $rulePrice === null) {
            return new PriceLayer(PriceLayer::CODE_CATALOG_RULE, null, false, false, [], $rules);
        }

        return new PriceLayer(PriceLayer::CODE_CATALOG_RULE, (float)$rulePrice, true, false, [], $rules);
    }

    /**
     * Names of matching catalog rules in sort order, up to the first one that stops further rules.
     *
     * @param array[] $rows catalogrule_product rows
     * @return string[]
     */
    private function ruleNames(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        usort(
            $rows,
            static fn (array $a, array $b): int => [(int)$a['sort_order'], (int)$a['rule_id']]
                <=> [(int)$b['sort_order'], (int)$b['rule_id']]
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['rule_id'];
            if (!empty($row['action_stop'])) {
                break;
            }
        }
        $ids = array_values(array_unique($ids));

        $names = [];
        $collection = $this->catalogRuleCollectionFactory->create()->addFieldToFilter('rule_id', ['in' => $ids]);
        foreach ($collection->getItems() as $rule) {
            $names[(int)$rule->getId()] = (string)$rule->getName();
        }

        return array_map(static fn (int $id): string => $names[$id] ?? '#' . $id, $ids);
    }

    /**
     * Code of the active layer that wins min(); on a tie the earlier layer wins, as core keeps the price.
     *
     * @param PriceLayer[] $layers
     * @return string
     */
    private function winner(array $layers): string
    {
        $winner = PriceLayer::CODE_BASE;
        foreach ($layers as $code => $layer) {
            if ($layer->isActive() && $layer->getValue() !== null
                && $layer->getValue() < (float)$layers[$winner]->getValue()
            ) {
                $winner = $code;
            }
        }

        return $winner;
    }

    /**
     * Copy of the layer marked as the min() winner.
     *
     * @param PriceLayer $layer
     * @return PriceLayer
     */
    private function markWon(PriceLayer $layer): PriceLayer
    {
        return new PriceLayer(
            $layer->getCode(),
            $layer->getValue(),
            $layer->isActive(),
            true,
            $layer->getDetails(),
            $layer->getRules()
        );
    }

    /**
     * Sum of selected custom option prices, mirroring Type\Price::_applyOptionsPrice; null without options.
     *
     * @param Product $product product carrying the item's option_ids (the parent for a configurable)
     * @param float $basePrice price percent options are calculated from
     * @return float|null
     */
    private function optionsPrice(Product $product, float $basePrice): ?float
    {
        $optionIds = $product->getCustomOption('option_ids');
        if (!$optionIds) {
            return null;
        }
        $sum = 0.0;
        foreach (explode(',', (string)$optionIds->getValue()) as $optionId) {
            $option = $product->getOptionById($optionId);
            if (!$option) {
                continue;
            }
            $itemOption = $product->getCustomOption('option_' . $option->getId());
            $sum += (float)$option->groupFactory($option->getType())
                ->setOption($option)
                ->setConfigurationItemOption($itemOption)
                ->getOptionPrice($itemOption->getValue(), $basePrice);
        }

        return $sum;
    }

    /**
     * Enabled non-Magento observers of catalog_product_get_final_price.
     *
     * @return string[]
     */
    private function suspects(): array
    {
        $suspects = [];
        foreach ($this->eventConfig->getObservers(self::EVENT) as $observer) {
            $instance = ltrim((string)($observer['instance'] ?? ''), '\\');
            if ($instance !== '' && empty($observer['disabled']) && !str_starts_with($instance, 'Magento\\')) {
                $suspects[] = $instance;
            }
        }

        return $suspects;
    }
}
