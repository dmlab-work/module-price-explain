<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Tax of one taxable line (an item or shipping) with the rates applied to it.
 */
class TaxLine
{
    use FormatsAmounts;

    public const TYPE_ITEM = 'item';
    public const TYPE_SHIPPING = 'shipping';

    /**
     * @param string $type TYPE_*
     * @param int|null $itemId null for shipping
     * @param string $label item label (parent SKU → child SKU) or "shipping"
     * @param string|null $taxClass product (or shipping) tax class name
     * @param float $amount tax amount
     * @param array[] $rates each with code, title, percent, amount
     * @param string|null $reason why there is no tax
     */
    public function __construct(
        private readonly string $type,
        private readonly ?int $itemId,
        private readonly string $label,
        private readonly ?string $taxClass,
        private readonly float $amount,
        private readonly array $rates = [],
        private readonly ?string $reason = null
    ) {
    }

    /**
     * TYPE_* value.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Quote item ID; null for shipping.
     *
     * @return int|null
     */
    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    /**
     * SKU or "shipping".
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Tax class name.
     *
     * @return string|null
     */
    public function getTaxClass(): ?string
    {
        return $this->taxClass;
    }

    /**
     * Tax amount.
     *
     * @return float
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Applied rates.
     *
     * @return array[]
     */
    public function getRates(): array
    {
        return $this->rates;
    }

    /**
     * Why there is no tax.
     *
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->reason;
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
            'type' => $this->type,
            'item_id' => $this->itemId,
            'label' => $this->label,
            'tax_class' => $this->taxClass,
            'amount' => $this->formatAmount($this->amount, $precision),
            'rates' => $this->formatRates($this->rates, $precision),
            'reason' => $this->reason,
        ];
    }
}
