<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Formats report numbers as strings so JSON output is exact and stable.
 */
trait FormatsAmounts
{
    /**
     * Amount with a fixed number of decimals; null stays null, -0 becomes 0.
     *
     * @param float|null $value
     * @param int $precision
     * @return string|null
     */
    private function formatAmount(?float $value, int $precision): ?string
    {
        if ($value === null) {
            return null;
        }
        $rounded = round($value, $precision);

        return number_format($rounded == 0 ? 0.0 : $rounded, $precision, '.', '');
    }

    /**
     * Percent without trailing zeros (8.25, 10).
     *
     * @param float $value
     * @return string
     */
    private function formatPercent(float $value): string
    {
        return rtrim(rtrim((string)$this->formatAmount($value, 4), '0'), '.');
    }

    /**
     * Format every amount of a code => amount map.
     *
     * @param float[] $amounts
     * @param int $precision
     * @return string[]
     */
    private function formatAmounts(array $amounts, int $precision): array
    {
        return array_map(fn (float $value): string => (string)$this->formatAmount($value, $precision), $amounts);
    }

    /**
     * Format applied tax rates.
     *
     * @param array[] $rates each with code, title, percent, amount
     * @param int $precision
     * @return array[]
     */
    private function formatRates(array $rates, int $precision): array
    {
        return array_map(
            fn (array $rate): array => [
                'code' => (string)$rate['code'],
                'title' => (string)$rate['title'],
                'percent' => $this->formatPercent((float)$rate['percent']),
                'amount' => $this->formatAmount((float)$rate['amount'], $precision),
            ],
            array_values($rates)
        );
    }

    /**
     * Qty as int when whole, so it survives a JSON round trip unchanged.
     *
     * @param float $qty
     * @return int|float
     */
    private function formatQty(float $qty): int|float
    {
        return floor($qty) === $qty ? (int)$qty : $qty;
    }
}
