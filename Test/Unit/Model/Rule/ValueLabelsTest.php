<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Rule;

use DmLab\PriceExplain\Model\Rule\ValueLabels;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueLabelsTest extends TestCase
{
    /**
     * @return array<string, array{string, array, mixed, bool, string[]|null}>
     */
    public static function labelsProvider(): array
    {
        $colors = [
            ['value' => '5', 'label' => 'Red'],
            ['value' => '6', 'label' => 'Blue'],
            ['value' => '', 'label' => ' '],
        ];
        $grouped = [
            ['label' => 'Flat Rate', 'value' => [['value' => 'flatrate_flatrate', 'label' => '[flatrate] Fixed']]],
        ];

        return [
            'null value' => ['select', $colors, null, true, null],
            'select option' => ['select', $colors, '5', false, ['Red']],
            'unknown option stays raw' => ['select', $colors, '9', false, ['9']],
            'actual de-duplicated and sorted' => ['multiselect', $colors, ['6', '5', '6'], true, ['Blue', 'Red']],
            'expected keeps configured order' => ['multiselect', $colors, ['6', '5', '6'], false, ['Blue', 'Red']],
            'option groups flattened' => ['select', $grouped, 'flatrate_flatrate', true, ['[flatrate] Fixed']],
            'boolean input' => ['boolean', [], ['1', '0'], true, ['No', 'Yes']],
            'php boolean' => ['string', [], true, true, ['Yes']],
            'numbers sorted naturally' => ['string', [], [32, 2, 18, 2], true, ['2', '18', '32']],
            'empty list' => ['string', [], [], true, []],
            'object' => ['string', [], [new \stdClass()], true, ['stdClass']],
        ];
    }

    /**
     * @param string $inputType
     * @param array $options
     * @param mixed $value
     * @param bool $actual
     * @param string[]|null $expected
     */
    #[DataProvider('labelsProvider')]
    public function testLabels(string $inputType, array $options, mixed $value, bool $actual, ?array $expected): void
    {
        $node = $this->createStub(AbstractCondition::class);
        $node->method('getInputType')->willReturn($inputType);
        $node->method('getValueSelectOptions')->willReturn($options);

        self::assertSame($expected, (new ValueLabels())->labels($node, $value, $actual));
    }

    public function testExpectedListIsNotSorted(): void
    {
        $node = $this->createStub(AbstractCondition::class);
        $node->method('getInputType')->willReturn('string');

        self::assertSame(['b', 'a'], (new ValueLabels())->labels($node, ['b', 'a', 'b'], false));
        self::assertSame(['a', 'b'], (new ValueLabels())->labels($node, ['b', 'a', 'b'], true));
    }

    public function testCategoryIdsWaitForNames(): void
    {
        $node = $this->createStub(ProductCondition::class);
        $node->method('getAttribute')->willReturn('category_ids');
        $labels = new ValueLabels();

        self::assertSame(ValueLabels::INPUT_CATEGORY, $labels->inputType($node));
        self::assertSame(['#25', '#16'], $labels->labels($node, ['25', '16', '25'], false));
        self::assertSame(['#2', '#13', '#18'], $labels->labels($node, [18, 2, 2, 13, 18], true));
    }

    public function testBrokenThirdPartyConditionFallsBackToRawValues(): void
    {
        $node = $this->createStub(AbstractCondition::class);
        $node->method('getInputType')->willThrowException(new \RuntimeException('boom'));
        $node->method('getValueSelectOptions')->willThrowException(new \RuntimeException('boom'));
        $labels = new ValueLabels();

        self::assertSame('string', $labels->inputType($node));
        self::assertSame(['1'], $labels->labels($node, 1, true));
    }

    public function testMergeAndJoin(): void
    {
        $labels = new ValueLabels();

        self::assertSame(['a', 'B', 'c'], $labels->merge([['c', 'a'], null, ['B', 'a'], []]));
        self::assertSame([], $labels->merge([[]]), 'an empty value keeps its empty list');
        self::assertNull($labels->merge([null]));
        self::assertSame('[a, b]', $labels->join(['a', 'b'], ['1', '2']));
        self::assertSame('a', $labels->join(['a'], '1'));
        self::assertNull($labels->join(null, '1'));
    }
}
