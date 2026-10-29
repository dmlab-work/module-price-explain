<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Quote totals after the recollection and, for an order report, the totals the order was placed with.
 */
class Totals
{
    use FormatsAmounts;

    public const SUBTOTAL = 'subtotal';
    public const DISCOUNT = 'discount';
    public const SHIPPING = 'shipping';
    public const TAX = 'tax';
    public const GRAND_TOTAL = 'grand_total';

    public const CODES = [self::SUBTOTAL, self::DISCOUNT, self::SHIPPING, self::TAX, self::GRAND_TOTAL];

    /**
     * @var float[]
     */
    private readonly array $current;

    /**
     * @var float[]
     */
    private readonly array $baseCurrent;

    /**
     * @var float[]|null
     */
    private readonly ?array $ordered;

    /**
     * @var float[]|null
     */
    private readonly ?array $baseOrdered;

    /**
     * Missing codes count as 0.
     *
     * @param float[] $current code => amount in quote currency
     * @param float[] $baseCurrent code => amount in base currency
     * @param string|null $baseCurrency
     * @param float[]|null $ordered order amounts in order currency; null for a quote report
     * @param float[]|null $baseOrdered order amounts in base currency
     */
    public function __construct(
        array $current,
        array $baseCurrent,
        private readonly ?string $baseCurrency = null,
        ?array $ordered = null,
        ?array $baseOrdered = null
    ) {
        $this->current = $this->normalize($current);
        $this->baseCurrent = $this->normalize($baseCurrent);
        $this->ordered = $ordered === null ? null : $this->normalize($ordered);
        $this->baseOrdered = $ordered === null ? null : $this->normalize($baseOrdered ?? []);
    }

    /**
     * Recollected amounts, quote currency.
     *
     * @return float[]
     */
    public function getCurrent(): array
    {
        return $this->current;
    }

    /**
     * Recollected amounts, base currency.
     *
     * @return float[]
     */
    public function getBaseCurrent(): array
    {
        return $this->baseCurrent;
    }

    /**
     * Base currency code.
     *
     * @return string|null
     */
    public function getBaseCurrency(): ?string
    {
        return $this->baseCurrency;
    }

    /**
     * Order amounts; null for a quote report.
     *
     * @return float[]|null
     */
    public function getOrdered(): ?array
    {
        return $this->ordered;
    }

    /**
     * Order amounts in base currency; null for a quote report.
     *
     * @return float[]|null
     */
    public function getBaseOrdered(): ?array
    {
        return $this->baseOrdered;
    }

    /**
     * Codes whose ordered and current amounts differ at the precision; empty for a quote report.
     *
     * @param int $precision
     * @return string[]
     */
    public function getDifferences(int $precision): array
    {
        if ($this->ordered === null) {
            return [];
        }
        $ordered = $this->formatAmounts($this->ordered, $precision);
        $current = $this->formatAmounts($this->current, $precision);

        return array_keys(array_diff_assoc($ordered, $current));
    }

    /**
     * JSON-ready totals.
     *
     * @param int $precision
     * @return array<string, mixed>
     */
    public function toArray(int $precision = 2): array
    {
        return [
            'base_currency' => $this->baseCurrency,
            'labels' => array_combine(self::CODES, array_map(Labels::total(...), self::CODES)),
            'current' => $this->formatAmounts($this->current, $precision),
            'base_current' => $this->formatAmounts($this->baseCurrent, $precision),
            'ordered' => $this->ordered === null ? null : $this->formatAmounts($this->ordered, $precision),
            'base_ordered' => $this->baseOrdered === null ? null : $this->formatAmounts($this->baseOrdered, $precision),
            'differences' => $this->getDifferences($precision),
        ];
    }

    /**
     * Every code in CODES order, as float.
     *
     * @param array $amounts
     * @return float[]
     */
    private function normalize(array $amounts): array
    {
        $result = [];
        foreach (self::CODES as $code) {
            $result[$code] = (float)($amounts[$code] ?? 0);
        }

        return $result;
    }
}
