<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Product\AbstractProduct;

/**
 * Display labels of condition values: option labels, Yes/No for booleans, `#id` for categories until resolved.
 */
class ValueLabels
{
    public const INPUT_CATEGORY = 'category';

    /**
     * Input type of a condition; category for product category conditions.
     *
     * @param AbstractCondition $node
     * @return string
     */
    public function inputType(AbstractCondition $node): string
    {
        if ($node instanceof AbstractProduct && $node->getAttribute() === 'category_ids') {
            return self::INPUT_CATEGORY;
        }
        try {
            return (string)$node->getInputType();
        } catch (\Throwable $e) {
            return 'string';
        }
    }

    /**
     * Labels of a configured (de-duplicated) or compared (de-duplicated and sorted) value; null without a value.
     *
     * @param AbstractCondition $node
     * @param mixed $value
     * @param bool $actual
     * @return string[]|null
     */
    public function labels(AbstractCondition $node, mixed $value, bool $actual): ?array
    {
        $type = $this->inputType($node);
        if ($type === self::INPUT_CATEGORY) {
            return $this->format($value, static fn (string $id): string => '#' . $id, $actual);
        }
        $options = $this->options($node);
        $boolean = $type === 'boolean';

        return $this->format(
            $value,
            static fn (string $v): string => $options[$v]
                ?? ($boolean && in_array($v, ['0', '1'], true) ? (string)__($v === '1' ? 'Yes' : 'No') : $v),
            $actual
        );
    }

    /**
     * Value as a list of labels; scalars become one label, booleans Yes/No.
     *
     * @param mixed $value
     * @param callable $label (string): string
     * @param bool $sort
     * @return string[]|null
     */
    public function format(mixed $value, callable $label, bool $sort): ?array
    {
        if ($value === null) {
            return null;
        }
        // Distinct raw values first: different IDs may share a label.
        $labels = [];
        foreach (is_array($value) ? $value : [$value] as $v) {
            $key = is_bool($v) ? 'b' . (int)$v : (is_scalar($v) ? 's' . $v : 'o' . count($labels));
            $labels[$key] ??= match (true) {
                is_bool($v) => (string)__($v ? 'Yes' : 'No'),
                is_scalar($v) => $label((string)$v),
                default => get_debug_type($v),
            };
        }
        if ($sort) {
            natcasesort($labels);
        }

        return array_values($labels);
    }

    /**
     * Label lists merged, de-duplicated and sorted; null without any list.
     *
     * @param array<string[]|null> $lists
     * @return string[]|null
     */
    public function merge(array $lists): ?array
    {
        $lists = array_filter($lists, static fn (?array $l): bool => $l !== null);
        if ($lists === []) {
            return null;
        }
        $labels = array_values(array_unique(array_merge(...array_values($lists))));
        natcasesort($labels);

        return array_values($labels);
    }

    /**
     * Labels as display text, bracketed for list values; null without labels.
     *
     * @param string[]|null $labels
     * @param mixed $raw
     * @return string|null
     */
    public function join(?array $labels, mixed $raw): ?string
    {
        if ($labels === null) {
            return null;
        }
        $text = implode(', ', $labels);

        return is_array($raw) ? '[' . $text . ']' : $text;
    }

    /**
     * Value options of the condition as value => label, option groups flattened.
     *
     * @param AbstractCondition $node
     * @return array<string, string>
     */
    private function options(AbstractCondition $node): array
    {
        try {
            $options = (array)$node->getValueSelectOptions();
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($options as $option) {
            if (!is_array($option) || !array_key_exists('value', $option)) {
                continue;
            }
            if (is_array($option['value'])) {
                foreach ($option['value'] as $nested) {
                    if (is_array($nested) && isset($nested['value']) && is_scalar($nested['value'])) {
                        $map[(string)$nested['value']] = (string)($nested['label'] ?? $nested['value']);
                    }
                }
            } elseif (is_scalar($option['value']) && (string)$option['value'] !== '') {
                $map[(string)$option['value']] = (string)($option['label'] ?? $option['value']);
            }
        }

        return $map;
    }
}
