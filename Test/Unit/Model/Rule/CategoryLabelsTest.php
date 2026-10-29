<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Rule;

use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Rule\CategoryLabels;
use DmLab\PriceExplain\Model\Rule\ValueLabels;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class CategoryLabelsTest extends TestCase
{
    public function testOneStoreScopedLookupNamesEveryTree(): void
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setStoreId', 'addAttributeToSelect', 'addIdFilter', 'getIterator'])
            ->getMock();
        $collection->expects(self::once())->method('setStoreId')->with(3)->willReturnSelf();
        $collection->expects(self::once())->method('addAttributeToSelect')->with('name')->willReturnSelf();
        $collection->expects(self::once())->method('addIdFilter')->with([25, 16, 18, 2, 40])->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['id' => 25, 'name' => 'Tees']),
            new DataObject(['id' => 16, 'name' => 'Tees']),
            new DataObject(['id' => 18, 'name' => 'Pants']),
            new DataObject(['id' => 2, 'name' => '']),
        ]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::once())->method('create')->willReturn($collection);

        $conditions = new ConditionNode('1', 'root', false, true, 'all', true, null, [
            $this->category('1--1', ['25', '16'], [18, 2, 18]),
            new ConditionNode('1--2', 'SKU is A', false, true, null, 'A', 'B', [], null, null, null, ['A'], ['B']),
        ]);
        $outcome = new RuleItemOutcome(1, 'P', false, null, null, null, null, $this->category('1', ['40'], []), 'P', [
            new RuleItemOutcome(2, 'C', false, null, null, null, null, $this->category('1', ['18'], [])),
        ]);
        $verdicts = [
            new RuleVerdict(1, 'Tees', 0, Status::REJECTED, null, null, [], null, $conditions),
            new RuleVerdict(2, 'Pants', 0, Status::APPLIED, null, null, [$outcome]),
        ];

        [$first, $second] = (new CategoryLabels($factory, new ValueLabels()))->apply($verdicts, 3);

        [$category, $sku] = $first->getConditions()->getChildren();
        self::assertSame('Category is one of Tees (#25), Tees (#16)', $category->getText());
        self::assertSame(['#2', 'Pants'], $category->getActualLabels(), 'nameless category keeps its ID');
        self::assertSame(['B'], $sku->getActualLabels());
        $items = $second->getItems();
        self::assertSame(['#40'], $items[0]->getActions()->getExpectedLabels(), 'unknown ID');
        self::assertSame(['Pants'], $items[0]->getChildren()[0]->getActions()->getExpectedLabels());
        self::assertSame('P', $items[0]->getLabel());
    }

    public function testFoldedLeafNamesEveryItemsCategories(): void
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setStoreId', 'addAttributeToSelect', 'addIdFilter', 'getIterator'])
            ->getMock();
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->expects(self::once())->method('addIdFilter')->with([25, 3, 18])->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['id' => 25, 'name' => 'Tees']),
            new DataObject(['id' => 3, 'name' => 'Bottoms']),
            new DataObject(['id' => 18, 'name' => 'Pants']),
        ]));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $leaf = $this->category('1--1@1--1', ['25'], ['3'])->withMemberActuals(false, [
            ['label' => 'MP01', 'actual' => ['3'], 'actual_label' => '[#3]', 'result' => false],
            ['label' => 'MP01-32-Black', 'actual' => ['18'], 'actual_label' => '[#18]', 'result' => false],
        ], ['#18', '#3']);
        $verdicts = [new RuleVerdict(1, 'Tees', 0, Status::REJECTED, null, null, [], null, $leaf)];

        [$verdict] = (new CategoryLabels($factory, new ValueLabels()))->apply($verdicts, 1);

        $folded = $verdict->getConditions();
        self::assertSame(['[Bottoms]', '[Pants]'], array_column($folded->getMemberActuals(), 'actual_label'));
        self::assertSame(['Bottoms', 'Pants'], $folded->getActualLabels(), 'union of the items, not the first one');
        self::assertFalse($folded->getResult());
    }

    public function testNoCategoryConditionMeansNoLookup(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::never())->method('create');
        $tree = new ConditionNode('1', 'x', false);
        $verdicts = [new RuleVerdict(1, 'A', 0, Status::REJECTED, null, null, [], null, $tree)];

        self::assertSame($verdicts, (new CategoryLabels($factory, new ValueLabels()))->apply($verdicts, 1));
    }

    /**
     * @param string $path
     * @param string[] $expected
     * @param int[] $actual
     * @return ConditionNode
     */
    private function category(string $path, array $expected, array $actual): ConditionNode
    {
        $labels = static fn (array $ids): array => array_map(static fn ($id): string => '#' . $id, $ids);

        return new ConditionNode(
            $path,
            'Category is one of ' . implode(',', $expected),
            false,
            true,
            null,
            $expected,
            $actual,
            [],
            null,
            null,
            'Category is one of',
            $labels($expected),
            $labels($actual),
            ValueLabels::INPUT_CATEGORY
        );
    }
}
