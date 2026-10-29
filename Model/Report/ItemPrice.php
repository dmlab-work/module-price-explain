<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Price layers of one quote item and how they add up to the item price.
 */
class ItemPrice
{
    use FormatsAmounts;

    /**
     * @param int $itemId
     * @param string $sku
     * @param string $name
     * @param float $qty
     * @param int $productId priced product (the child for a configurable)
     * @param PriceLayer[] $layers
     * @param float|null $final computed final price
     * @param float $itemPrice the item's actual calculation price
     * @param float|null $customPrice custom-price override
     * @param float|null $unexplainedDelta item price minus computed final, when not zero
     * @param string[] $suspects third-party observers that may change the final price
     * @param string|null $label item label, defaults to the SKU
     */
    public function __construct(
        private readonly int $itemId,
        private readonly string $sku,
        private readonly string $name,
        private readonly float $qty,
        private readonly int $productId,
        private readonly array $layers,
        private readonly ?float $final,
        private readonly float $itemPrice,
        private readonly ?float $customPrice = null,
        private readonly ?float $unexplainedDelta = null,
        private readonly array $suspects = [],
        private readonly ?string $label = null
    ) {
    }

    /**
     * Quote item ID.
     *
     * @return int
     */
    public function getItemId(): int
    {
        return $this->itemId;
    }

    /**
     * Item SKU.
     *
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * Item label (parent SKU → child SKU).
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label ?? $this->sku;
    }

    /**
     * Item name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Item qty.
     *
     * @return float
     */
    public function getQty(): float
    {
        return $this->qty;
    }

    /**
     * Priced product ID.
     *
     * @return int
     */
    public function getProductId(): int
    {
        return $this->productId;
    }

    /**
     * Price layers.
     *
     * @return PriceLayer[]
     */
    public function getLayers(): array
    {
        return $this->layers;
    }

    /**
     * Layer that won min(), if any.
     *
     * @return PriceLayer|null
     */
    public function getWinningLayer(): ?PriceLayer
    {
        foreach ($this->layers as $layer) {
            if ($layer->isWon()) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * Computed final price.
     *
     * @return float|null
     */
    public function getFinal(): ?float
    {
        return $this->final;
    }

    /**
     * Actual calculation price of the item.
     *
     * @return float
     */
    public function getItemPrice(): float
    {
        return $this->itemPrice;
    }

    /**
     * Custom-price override.
     *
     * @return float|null
     */
    public function getCustomPrice(): ?float
    {
        return $this->customPrice;
    }

    /**
     * Item price minus computed final.
     *
     * @return float|null
     */
    public function getUnexplainedDelta(): ?float
    {
        return $this->unexplainedDelta;
    }

    /**
     * Third-party observer classes that may explain the delta.
     *
     * @return string[]
     */
    public function getSuspects(): array
    {
        return $this->suspects;
    }

    /**
     * JSON-ready representation.
     *
     * @param int $precision
     * @return array<string, mixed>
     */
    public function toArray(int $precision = 2): array
    {
        return [
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'label' => $this->getLabel(),
            'name' => $this->name,
            'qty' => $this->formatQty($this->qty),
            'product_id' => $this->productId,
            'layers' => array_map(static fn (PriceLayer $l): array => $l->toArray($precision), $this->layers),
            'final' => $this->formatAmount($this->final, $precision),
            'item_price' => $this->formatAmount($this->itemPrice, $precision),
            'custom_price' => $this->formatAmount($this->customPrice, $precision),
            'unexplained_delta' => $this->formatAmount($this->unexplainedDelta, $precision),
            'suspects' => array_values($this->suspects),
        ];
    }
}
