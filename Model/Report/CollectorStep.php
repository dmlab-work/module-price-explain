<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * One totals collector run on one address and the amounts it changed.
 */
class CollectorStep
{
    use FormatsAmounts;

    /**
     * @param string $code collector code from sales.xml
     * @param string $class real collector class (no Interceptor suffix)
     * @param bool $thirdParty class outside the Magento\ namespace
     * @param string $addressType shipping|billing
     * @param float[] $delta total code => change in quote currency
     * @param float[] $baseDelta total code => change in base currency
     * @param int|null $sortOrder sales.xml sort_order, null when not configured
     */
    public function __construct(
        private readonly string $code,
        private readonly string $class,
        private readonly bool $thirdParty,
        private readonly string $addressType,
        private readonly array $delta,
        private readonly array $baseDelta = [],
        private readonly ?int $sortOrder = null
    ) {
    }

    /**
     * Collector code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Collector class.
     *
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Whether the collector is not a core one.
     *
     * @return bool
     */
    public function isThirdParty(): bool
    {
        return $this->thirdParty;
    }

    /**
     * Address type the collector ran on.
     *
     * @return string
     */
    public function getAddressType(): string
    {
        return $this->addressType;
    }

    /**
     * Changes in quote currency.
     *
     * @return float[]
     */
    public function getDelta(): array
    {
        return $this->delta;
    }

    /**
     * Changes in base currency.
     *
     * @return float[]
     */
    public function getBaseDelta(): array
    {
        return $this->baseDelta;
    }

    /**
     * Collector sort order from sales.xml.
     *
     * @return int|null
     */
    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    /**
     * Whether the collector changed any amount in either currency.
     *
     * @return bool
     */
    public function hasChanges(): bool
    {
        return $this->delta !== [] || $this->baseDelta !== [];
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
            'class' => $this->class,
            'third_party' => $this->thirdParty,
            'address' => $this->addressType,
            'sort_order' => $this->sortOrder,
            'delta' => $this->formatAmounts($this->delta, $precision),
            'base_delta' => $this->formatAmounts($this->baseDelta, $precision),
        ];
    }
}
