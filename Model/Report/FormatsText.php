<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Report values as unescaped display text for the CLI printer.
 */
trait FormatsText
{
    use FormatsAmounts;

    /**
     * Rates as "code 8.25% 1.65; …".
     *
     * @param array[] $rates
     * @param int $precision
     * @return string
     */
    private function rates(array $rates, int $precision): string
    {
        return implode('; ', array_map(
            static fn (array $rate): string => sprintf('%s %s%% %s', $rate['code'], $rate['percent'], $rate['amount']),
            $this->formatRates($rates, $precision)
        ));
    }

    /**
     * Map as "key=value, …".
     *
     * @param array $map
     * @return string
     */
    private function pairs(array $map): string
    {
        $parts = [];
        foreach ($map as $key => $value) {
            $parts[] = $key . '=' . $this->value($value);
        }

        return implode(', ', $parts);
    }

    /**
     * Price layer details and catalog rule names as one cell.
     *
     * @param PriceLayer $layer
     * @return string
     */
    private function layerNote(PriceLayer $layer): string
    {
        $note = $this->pairs($layer->getDetails());
        if ($layer->getRules() !== []) {
            $note .= ($note === '' ? '' : ', ') . 'rules: ' . implode(', ', $layer->getRules());
        }

        return $note;
    }

    /**
     * Collector changes as "code +1.00, …"; "—" when nothing changed.
     *
     * @param CollectorStep $step
     * @param int $precision
     * @return string
     */
    private function collectorChanges(CollectorStep $step, int $precision): string
    {
        $changes = [];
        foreach ($step->getDelta() as $code => $delta) {
            $changes[] = $code . ' ' . $this->signed($delta, $precision);
        }

        return $changes === [] ? '—' : implode(', ', $changes);
    }

    /**
     * Collector steps grouped by address type, addresses in execution order.
     *
     * @param CollectorStep[] $steps
     * @return array<string, CollectorStep[]>
     */
    private function collectorsByAddress(array $steps): array
    {
        $groups = [];
        foreach ($steps as $step) {
            $groups[$step->getAddressType()][] = $step;
        }

        return $groups;
    }

    /**
     * Name with its ID ("Main Website (#1)"); "#1" when the name is unknown.
     *
     * @param string|null $name
     * @param int $id
     * @return string
     */
    private function named(?string $name, int $id): string
    {
        return $name === null ? '#' . $id : sprintf('%s (#%d)', $name, $id);
    }

    /**
     * Customer as "Name <email> (#id)" or "Guest <email>"; null when unknown.
     *
     * @param QuoteScope|null $scope
     * @return string|null
     */
    private function customer(?QuoteScope $scope): ?string
    {
        if ($scope === null) {
            return null;
        }
        $parts = [$scope->isGuest() ? 'Guest' : ($scope->getCustomerName() ?? '')];
        if ($scope->getCustomerEmail() !== null) {
            $parts[] = '<' . $scope->getCustomerEmail() . '>';
        }
        if (!$scope->isGuest()) {
            $parts[] = '(#' . $scope->getCustomerId() . ')';
        }

        return implode(' ', array_filter($parts, 'strlen'));
    }

    /**
     * Free-shipping badge of a rule: "free shipping (For shipment with matching items): Effective"; "" when none.
     *
     * @param RuleVerdict $rule
     * @return string
     */
    private function freeShippingBadge(RuleVerdict $rule): string
    {
        if (!$rule->isFreeShipping()) {
            return '';
        }
        $badge = sprintf('free shipping (%s)', Labels::freeShippingMode((string)$rule->getFreeShippingMode()));
        $outcome = $rule->getFreeShippingOutcome();

        return $outcome === null ? $badge : $badge . ': ' . Labels::freeShippingVerdict($outcome);
    }

    /**
     * Free-shipping section facts as label => value.
     *
     * @param FreeShipping $freeShipping
     * @param int $precision
     * @return array<string, string>
     */
    private function freeShippingFacts(FreeShipping $freeShipping, int $precision): array
    {
        $rules = array_map(
            static fn (array $rule): string => sprintf(
                '#%d %s (%s)',
                $rule['id'],
                $rule['name'],
                Labels::freeShippingMode((string)$rule['mode'])
            ),
            $freeShipping->getRules()
        );
        $items = array_map(
            fn (array $item): string => sprintf('%s × %s', $item['sku'], $this->formatQty((float)$item['qty'])),
            $freeShipping->getItems()
        );
        $subtotal = $freeShipping->getFreeCarrierSubtotal();
        $savedAt = $freeShipping->getRateUpdatedAt();

        return [
            'Granted by' => $rules === [] ? '—' : implode(', ', $rules),
            'Address flag' => $this->value($freeShipping->hasAddressFlag()),
            'Free items' => $items === [] ? '—' : implode(', ', $items),
            'Free boxes' => sprintf(
                '%s of %s',
                $this->formatQty($freeShipping->getFreeBoxes()),
                $this->formatQty($freeShipping->getPackageQty())
            ),
            'Method' => $freeShipping->getMethod() ?? '—',
            'Stored rate (base currency)' => $this->amount($freeShipping->getRatePrice(), $precision)
                . ($savedAt !== null ? ' (saved ' . $savedAt . ')' : '')
                . ($freeShipping->isRateOlderThanQuote() ? ', older than the quote' : ''),
            'Carrier honours' => Labels::semantics($freeShipping->getSemantics()),
            'Free Shipping carrier' => Labels::enabled($freeShipping->isFreeCarrierEnabled())
                . ($freeShipping->isFreeCarrierEnabled() && $subtotal !== null
                    ? ', minimum ' . $this->amount($subtotal, $precision)
                    : ''),
        ];
    }

    /**
     * Scalar, list or null as text.
     *
     * @param mixed $value
     * @return string
     */
    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => '[' . implode(', ', array_map(fn ($v): string => $this->value($v), $value)) . ']',
            is_scalar($value) => (string)$value,
            default => get_debug_type($value),
        };
    }

    /**
     * Amount with precision; "—" when absent.
     *
     * @param float|null $value
     * @param int $precision
     * @return string
     */
    private function amount(?float $value, int $precision): string
    {
        return $this->formatAmount($value, $precision) ?? '—';
    }

    /**
     * Amount with an explicit sign.
     *
     * @param float $value
     * @param int $precision
     * @return string
     */
    private function signed(float $value, int $precision): string
    {
        $formatted = (string)$this->formatAmount($value, $precision);

        return str_starts_with($formatted, '-') ? $formatted : '+' . $formatted;
    }
}
