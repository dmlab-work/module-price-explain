<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Report;

use DmLab\PriceExplain\Model\Report\ConditionNode;
use PHPUnit\Framework\TestCase;

class ConditionNodeTest extends TestCase
{
    public function testTextUsesLabelsOnlyWithSubject(): void
    {
        $category = $this->category(['25', '16'], [18, 2], ['Pants', 'Tees'], ['Default Category', 'Men']);
        $plain = new ConditionNode('1', 'SKU is A', false, true, null, 'A', 'B', [], null, null, null, ['A'], ['B']);

        self::assertSame('Category is one of Pants, Tees', $category->getText());
        self::assertSame('Category is one of 25,16', $category->getDescription());
        self::assertSame('[Pants, Tees]', $category->getExpectedLabel());
        self::assertSame('[Default Category, Men]', $category->getActualLabel());
        self::assertSame('SKU is A', $plain->getText());
        self::assertSame('A', $plain->getExpectedLabel());
        self::assertSame('B', $plain->getActualLabel());
        self::assertNull((new ConditionNode('1', 'x', true))->getActualLabel());
    }

    public function testToArrayCarriesTextItemAndLabels(): void
    {
        $node = $this->category(['25'], [18], ['Tees'], ['Pants'])->withItem('MP01');

        self::assertSame(
            [
                'text' => 'Category is one of Tees',
                'item' => 'MP01',
                'input_type' => 'category',
                'expected_label' => '[Tees]',
                'actual_label' => '[Pants]',
            ],
            array_intersect_key(
                $node->toArray(),
                array_flip(['text', 'item', 'input_type', 'expected_label', 'actual_label'])
            )
        );
    }

    public function testMapRebuildsBottomUpAndFlattenListsDepthFirst(): void
    {
        $root = new ConditionNode('1', 'root', false, true, 'all', true, null, [
            new ConditionNode('1--1', 'group', false, true, 'all', true, null, [
                new ConditionNode('1--1--1', 'a', false),
            ]),
            new ConditionNode('1--2', 'b', true),
        ]);

        $mapped = $root->map(
            static fn (ConditionNode $n): ConditionNode => $n->getChildren() === [] ? $n->withLabels(['x'], ['y']) : $n
        );

        self::assertSame(['1', '1--1', '1--1--1', '1--2'], array_map(fn ($n) => $n->getPath(), $mapped->flatten()));
        self::assertSame(['x'], $mapped->getChildren()[0]->getChildren()[0]->getExpectedLabels());
        self::assertSame(['y'], $mapped->getChildren()[1]->getActualLabels());
        self::assertNull($root->getChildren()[1]->getActualLabels(), 'the original tree is unchanged');
        self::assertSame('all', $mapped->getAggregator());
    }

    public function testFailureSummariesListEachConditionOnceWithItems(): void
    {
        $found = $this->found(true, [
            'MP01 → MP01-32-Black' => ['Bottoms', 'Pants'],
            'MP01 → MP01-32-Black#child' => ['Pants', 'Men'],
            '24-UG06' => ['Gear'],
        ]);
        $subtotal = new ConditionNode('1--2', 'Subtotal >= 200', false, true, null, '200', 63.0);
        $root = new ConditionNode('1', 'root', false, true, 'all', true, null, [$found, $subtotal]);

        self::assertSame(
            [
                'Category is one of Pants, Tees — no item matches: MP01 → MP01-32-Black (Bottoms, Men, Pants), '
                . '24-UG06 (Gear)',
                'Subtotal >= 200',
            ],
            $root->getFailureSummaries()
        );
        self::assertSame(
            [
                ['MP01 → MP01-32-Black', true],
                ['MP01 → MP01-32-Black', true],
                ['24-UG06', true],
                [null, null],
            ],
            array_map(fn (array $c) => [$c['item'], $c['found']], $root->getFailingChecks())
        );
    }

    public function testNotFoundAndEntityItemsUseTheirOwnWording(): void
    {
        $notFound = $this->found(false, ['24-UG06' => ['Gear']]);
        $onChild = (new ConditionNode('1--1', 'SKU is X', false, true, null, 'X', 'C', [], null, null, null, ['X'], []))
            ->withItem('P → C');

        self::assertSame(
            ['Category is one of Pants, Tees — found: 24-UG06 (Gear)'],
            (new ConditionNode('1', 'root', false, true, 'all', true, null, [$notFound]))->getFailureSummaries()
        );
        self::assertSame(['SKU is X — P → C'], $onChild->getFailureSummaries());
    }

    public function testNegatedGroupReportsTheMatchingItemAsFound(): void
    {
        $leaf = $this->category(['25'], [25], ['Pants'], ['Pants'], true);
        $check = new ConditionNode('1--1@1', 'Item #1', true, true, 'all', true, null, [$leaf], null, 'MP01');
        $found = new ConditionNode('1--1', 'If an item is FOUND', true, true, 'all', null, [1], [$check]);
        $root = new ConditionNode('1', 'root', false, true, 'all', false, null, [$found]);

        self::assertSame(['Category is one of Pants — found: MP01 (Pants)'], $root->getFailureSummaries());
    }

    /**
     * @param string[] $expected
     * @param int[] $actual
     * @param string[] $expectedLabels
     * @param string[] $actualLabels
     * @param bool $result
     * @return ConditionNode
     */
    private function category(
        array $expected,
        array $actual,
        array $expectedLabels,
        array $actualLabels,
        bool $result = false
    ): ConditionNode {
        return new ConditionNode(
            '1--1',
            'Category is one of ' . implode(',', $expected),
            $result,
            true,
            null,
            $expected,
            $actual,
            [],
            null,
            null,
            'Category is one of',
            $expectedLabels,
            $actualLabels,
            'category'
        );
    }

    /**
     * Found node whose item checks fail on a category leaf; "#child" suffixes repeat a label.
     *
     * @param bool $value FOUND / NOT FOUND
     * @param array<string, string[]> $items label => actual category labels
     * @return ConditionNode
     */
    private function found(bool $value, array $items): ConditionNode
    {
        $checks = [];
        $i = 0;
        foreach ($items as $label => $actual) {
            $i++;
            $label = explode('#', $label)[0];
            // An item check fails on a leaf that is false under FOUND and true under NOT FOUND.
            $leaf = $this->category(['25', '16'], [], ['Pants', 'Tees'], $actual, !$value);
            $checks[] = new ConditionNode(
                '1--1@' . $i,
                'Item #' . $i,
                false,
                true,
                'all',
                $value,
                null,
                [$leaf],
                null,
                $label
            );
        }

        return new ConditionNode('1--1', 'If an item is FOUND', false, true, 'all', null, [], $checks);
    }
}
