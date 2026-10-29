<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Tax section of the report: inputs, config flags and per-line rates.
 */
class TaxBreakdown
{
    use FormatsAmounts;

    /**
     * @param string $basedOn tax/calculation/based_on
     * @param string $algorithm tax/calculation/algorithm
     * @param string|null $customerTaxClass
     * @param array $address address used (country, region, postcode)
     * @param bool[] $flags tax config flags that change the result
     * @param TaxLine[] $lines
     * @param array[] $appliedTaxes address level, same shape as TaxLine rates
     */
    public function __construct(
        private readonly string $basedOn,
        private readonly string $algorithm,
        private readonly ?string $customerTaxClass = null,
        private readonly array $address = [],
        private readonly array $flags = [],
        private readonly array $lines = [],
        private readonly array $appliedTaxes = []
    ) {
    }

    /**
     * Configured tax address source.
     *
     * @return string
     */
    public function getBasedOn(): string
    {
        return $this->basedOn;
    }

    /**
     * Calculation algorithm.
     *
     * @return string
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Customer tax class name.
     *
     * @return string|null
     */
    public function getCustomerTaxClass(): ?string
    {
        return $this->customerTaxClass;
    }

    /**
     * Address used for rate lookup.
     *
     * @return array
     */
    public function getAddress(): array
    {
        return $this->address;
    }

    /**
     * "US, region 12, 90001 (Shipping address)"; empty when no address was recorded.
     *
     * @return string
     */
    public function getAddressLabel(): string
    {
        if ($this->address === []) {
            return '';
        }
        $country = $this->address['country_id'] ?? null;
        $region = $this->address['region_id'] ?? null;
        $postcode = $this->address['postcode'] ?? null;
        $parts = array_filter(
            [$country, $region ? (string)__('region %1', $region) : null, $postcode],
            static fn ($part): bool => $part !== null && $part !== ''
        );
        $location = $parts === [] ? '—' : implode(', ', $parts);

        return isset($this->address['source'])
            ? $location . ' (' . Labels::taxAddressSource((string)$this->address['source']) . ')'
            : $location;
    }

    /**
     * Tax config flags.
     *
     * @return bool[]
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * Labels of the tax config flags, keyed like the flags.
     *
     * @return string[]
     */
    public function getFlagLabels(): array
    {
        $labels = [];
        foreach (array_keys($this->flags) as $code) {
            $labels[$code] = Labels::taxFlag((string)$code);
        }

        return $labels;
    }

    /**
     * Per-line taxes.
     *
     * @return TaxLine[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Address-level applied taxes.
     *
     * @return array[]
     */
    public function getAppliedTaxes(): array
    {
        return $this->appliedTaxes;
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
            'based_on' => $this->basedOn,
            'based_on_label' => Labels::taxBasedOn($this->basedOn),
            'algorithm' => $this->algorithm,
            'algorithm_label' => Labels::taxAlgorithm($this->algorithm),
            'customer_tax_class' => $this->customerTaxClass,
            'address' => $this->address,
            'address_label' => $this->getAddressLabel(),
            'flags' => $this->flags,
            'flag_labels' => $this->getFlagLabels(),
            'lines' => array_map(static fn (TaxLine $l): array => $l->toArray($precision), $this->lines),
            'applied_taxes' => $this->formatRates($this->appliedTaxes, $precision),
        ];
    }
}
