<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * One price layer of an item (base, special, tier, catalog rule, custom options, ...).
 */
class PriceLayer
{
    use FormatsAmounts;

    public const CODE_BASE = 'base';
    public const CODE_SPECIAL = 'special';
    public const CODE_TIER = 'tier';
    public const CODE_CATALOG_RULE = 'catalog_rule';
    public const CODE_OPTIONS = 'custom_options';

    public const STATE_WON = 'won';
    public const STATE_ACTIVE = 'active';
    public const STATE_INACTIVE = 'inactive';

    /**
     * @param string $code
     * @param float|null $value null when the layer has no value for this item
     * @param bool $active false when set but not applicable (dates, qty)
     * @param bool $won whether this layer won min()
     * @param array $details e.g. from/to dates, tier qty
     * @param string[] $rules names of the catalog rules behind the layer
     */
    public function __construct(
        private readonly string $code,
        private readonly ?float $value,
        private readonly bool $active = true,
        private readonly bool $won = false,
        private readonly array $details = [],
        private readonly array $rules = []
    ) {
    }

    /**
     * Layer code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Layer value; null when absent.
     *
     * @return float|null
     */
    public function getValue(): ?float
    {
        return $this->value;
    }

    /**
     * Whether the layer applies to this item.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Whether this layer won min().
     *
     * @return bool
     */
    public function isWon(): bool
    {
        return $this->won;
    }

    /**
     * State: won, active or inactive (STATE_*).
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->won ? self::STATE_WON : ($this->active ? self::STATE_ACTIVE : self::STATE_INACTIVE);
    }

    /**
     * Extra facts (dates, qty).
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Catalog rule names.
     *
     * @return string[]
     */
    public function getRules(): array
    {
        return $this->rules;
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
            'code' => $this->code,
            'label' => Labels::layer($this->code),
            'value' => $this->formatAmount($this->value, $precision),
            'active' => $this->active,
            'won' => $this->won,
            'state' => $this->getState(),
            'state_label' => Labels::layerState($this->getState()),
            'details' => $this->details,
            'rules' => array_values($this->rules),
        ];
    }
}
