<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Quote;

use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Report label of a quote item: own SKU, or parent SKU → chosen child SKU (`MP01 → MP01-32-Black`).
 */
class ItemLabel
{
    /**
     * Label of a visible item or of a child item.
     *
     * @param AbstractItem $item
     * @return string
     */
    public function get(AbstractItem $item): string
    {
        $parent = $item->getParentItem();
        if ($parent) {
            return $this->join($this->ownSku($parent), $this->ownSku($item));
        }
        $children = $item->getChildren();
        // A configurable carries exactly one chosen child; children-calculated (bundle) parents keep their own SKU.
        if (count($children) === 1 && !$this->isChildrenCalculated($item)) {
            return $this->join($this->ownSku($item), $this->ownSku(reset($children)));
        }

        return $this->ownSku($item);
    }

    /**
     * Whether the item's discount and tax are calculated per child.
     *
     * @param AbstractItem $item
     * @return bool
     */
    public function isChildrenCalculated(AbstractItem $item): bool
    {
        $product = $item->getParentItem() ? $item->getParentItem()->getProduct() : $item->getProduct();

        return $product !== null && $item->isChildrenCalculated();
    }

    /**
     * Product's own SKU: the item SKU of a configurable is its child's.
     *
     * @param AbstractItem $item
     * @return string
     */
    public function ownSku(AbstractItem $item): string
    {
        $sku = $item->getProduct()?->getData('sku');

        return $sku !== null && $sku !== '' ? (string)$sku : (string)$item->getSku();
    }

    /**
     * "parent → child", or one SKU when both are the same.
     *
     * @param string $parent
     * @param string $child
     * @return string
     */
    private function join(string $parent, string $child): string
    {
        return $parent === $child ? $parent : $parent . ' → ' . $child;
    }
}
