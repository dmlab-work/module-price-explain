<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Rule;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Rule\ConditionWalker;
use DmLab\PriceExplain\Model\Rule\ValueLabels;
use DmLab\PriceExplain\Model\Trace\Tracer;
use DmLab\PriceExplain\Plugin\Rule\CaptureValidatedValuePlugin;
use DmLab\PriceExplain\Test\Unit\Fixture\Condition\AddressCondition;
use DmLab\PriceExplain\Test\Unit\Fixture\Condition\CatalogProduct;
use DmLab\PriceExplain\Test\Unit\Fixture\Condition\ProductCondition;
use DmLab\PriceExplain\Test\Unit\Fixture\Condition\SubselectCondition;
use DmLab\PriceExplain\Test\Unit\Fixture\Condition\VendorCondition;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductCategoryList;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Directory\Model\Config\Source\Allregion;
use Magento\Directory\Model\Config\Source\Country;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection as AttributeSetCollection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Locale\FormatInterface;
use Magento\Payment\Model\Config\Source\Allmethods as PaymentMethods;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Context;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Address as CoreAddressCondition;
use Magento\SalesRule\Model\Rule\Condition\Combine;
use Magento\SalesRule\Model\Rule\Condition\Product\Combine as ProductCombine;
use Magento\SalesRule\Model\Rule\Condition\Product\Found;
use Magento\Shipping\Model\Config\Source\Allmethods as ShippingMethods;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConditionWalkerTest extends TestCase
{
    /**
     * @var Tracer
     */
    private Tracer $tracer;

    /**
     * @var CaptureValidatedValuePlugin
     */
    private CaptureValidatedValuePlugin $plugin;

    /**
     * @var ProductCategoryList
     */
    private ProductCategoryList $categoryList;

    protected function setUp(): void
    {
        $this->tracer = new Tracer();
        $this->plugin = new CaptureValidatedValuePlugin($this->tracer);
        $this->categoryList = $this->createStub(ProductCategoryList::class);
    }

    public function testNestedTreeReportsEveryFailingLeafWithoutShortCircuit(): void
    {
        $address = $this->address(
            ['base_subtotal' => 93.6, 'total_qty' => 2, 'shipping_method' => 'flatrate_flatrate', 'country_id' => 'DE']
        );
        $root = $this->combine('all', true, [
            $this->addressLeaf('base_subtotal', '>=', '100'),
            $this->addressLeaf('total_qty', '>=', '1'),
            $this->combine('any', true, [
                $this->addressLeaf('shipping_method', '==', 'freeshipping_freeshipping'),
                $this->addressLeaf('country_id', '==', 'US'),
            ]),
        ]);

        $result = $this->walker()->walkConditions($this->rule($root), $address);
        $tree = $result->getTree();

        self::assertFalse($tree->getResult());
        self::assertFalse($result->isValid());
        self::assertNull($result->getWarning());
        self::assertSame('all', $tree->getAggregator());
        self::assertSame(['1--1', '1--2', '1--3'], array_map(fn ($c) => $c->getPath(), $tree->getChildren()));
        self::assertTrue($tree->getChildren()[1]->getResult());
        self::assertSame(
            [
                ['1--1', '100', 93.6],
                ['1--3--1', 'freeshipping_freeshipping', 'flatrate_flatrate'],
                ['1--3--2', 'US', 'DE'],
            ],
            array_map(
                fn (ConditionNode $n) => [$n->getPath(), $n->getExpected(), $n->getActual()],
                array_column($tree->getFailingChecks(), 'leaf')
            )
        );
        self::assertFalse($this->tracer->isCapturing());
    }

    /**
     * @return array<string, array{string, bool, bool[], bool}>
     */
    public static function aggregatorProvider(): array
    {
        return [
            'all true, all pass' => ['all', true, [true, true], true],
            'all true, one fails' => ['all', true, [true, false], false],
            'all false, none pass' => ['all', false, [false, false], true],
            'all false, one passes' => ['all', false, [true, false], false],
            'any true, one passes' => ['any', true, [false, true], true],
            'any true, none pass' => ['any', true, [false, false], false],
            'any false, one fails' => ['any', false, [true, false], true],
            'any false, all pass' => ['any', false, [true, true], false],
        ];
    }

    /**
     * @param string $aggregator
     * @param bool $value
     * @param bool[] $leaves
     * @param bool $expected
     */
    #[DataProvider('aggregatorProvider')]
    public function testAggregators(string $aggregator, bool $value, array $leaves, bool $expected): void
    {
        $address = $this->address(['total_qty' => 1]);
        $children = array_map(fn (bool $pass) => $this->addressLeaf('total_qty', '==', $pass ? '1' : '2'), $leaves);

        $rule = $this->rule($this->combine($aggregator, $value, $children));

        $result = $this->walker()->walkConditions($rule, $address);

        self::assertSame($expected, $result->getTree()->getResult());
        self::assertSame($expected, $result->isValid());
        self::assertCount(count($leaves), $result->getTree()->getChildren());
    }

    public function testEmptyConditionsAreTrue(): void
    {
        $result = $this->walker()->walkConditions($this->rule($this->combine('all', true, [])), $this->address([]));

        self::assertTrue($result->getTree()->getResult());
        self::assertSame([], $result->getTree()->getChildren());
        self::assertSame('If ALL of these conditions are TRUE:', $result->getTree()->getDescription());
    }

    public function testFallsBackToModelDataWhenNothingWasCaptured(): void
    {
        $leaf = $this->coreAddressLeaf('base_subtotal', '>=', '100');

        $tree = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$leaf])),
            $this->address(['base_subtotal' => 120])
        )->getTree();

        self::assertTrue($tree->getChildren()[0]->getResult());
        self::assertSame(120, $tree->getChildren()[0]->getActual());
    }

    public function testAddressConditionOnItemResolvesShippingOrBillingAddress(): void
    {
        foreach ([false => 'shipping', true => 'billing'] as $virtual => $type) {
            $quote = $this->createPartialMock(Quote::class, ['isVirtual', 'getShippingAddress', 'getBillingAddress']);
            $quote->method('isVirtual')->willReturn((bool)$virtual);
            $quote->method('getShippingAddress')->willReturn($this->address(['country_id' => 'US']));
            $quote->method('getBillingAddress')->willReturn($this->address(['country_id' => 'DE']));
            $item = $this->item(1, 'A', [], $quote);

            $node = $this->walker()->walkActions(
                $this->rule($this->combine('all', true, []), $this->productCombine('all', true, [
                    $this->coreAddressLeaf('country_id', '==', 'US'),
                ])),
                $item
            )->getChildren()[0];

            self::assertSame($type === 'shipping', $node->getResult(), $type);
            self::assertSame($type === 'shipping' ? 'US' : 'DE', $node->getActual(), $type);
        }
    }

    public function testFoundRecordsMatchingItems(): void
    {
        $items = [$this->item(1, 'A'), $this->item(2, 'B'), $this->item(3, 'A')];
        $found = $this->found(true, 'all', [$this->productLeaf('sku', '==', 'A')]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], $items)
        )->getTree()->getChildren()[0];

        self::assertTrue($node->getResult());
        self::assertSame([1, 3], $node->getActual());
        self::assertSame(['1--1@1', '1--1@2', '1--1@3'], array_map(fn ($c) => $c->getPath(), $node->getChildren()));
        self::assertSame('B', $node->getChildren()[1]->getDescription());
        self::assertSame([2], $node->getChildren()[1]->getItemIds());
        self::assertNull($node->getChildren()[1]->getChildren()[0]->getMemberActuals(), 'a simple item is not folded');
        self::assertSame('B', $node->getChildren()[1]->getChildren()[0]->getActual());
        self::assertStringContainsString('FOUND', $node->getDescription());
    }

    public function testNotFoundFailsWhenAnItemMatches(): void
    {
        $found = $this->found(false, 'all', [$this->productLeaf('sku', '==', 'A')]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], [$this->item(1, 'A')])
        )->getTree()->getChildren()[0];

        self::assertFalse($node->getResult());
        self::assertSame([1], $node->getActual());
        self::assertSame(
            ['1--1--1'],
            array_map(fn ($n) => $n->getPath(), array_column($node->getFailingChecks(), 'leaf'))
        );
    }

    public function testSubselectRecordsAggregatedTotal(): void
    {
        $items = [
            $this->item(1, 'A', ['qty' => 2]),
            $this->item(2, 'B', ['qty' => 3]),
            $this->item(3, 'A', ['qty' => 1]),
        ];
        $subselect = $this->subselect('qty', '>=', '5', [$this->productLeaf('sku', '==', 'A')]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$subselect])),
            $this->address([], $items, $items)
        )->getTree()->getChildren()[0];

        self::assertFalse($node->getResult());
        self::assertEquals(3, $node->getActual());
        self::assertSame('5', $node->getExpected());
        self::assertSame(['5'], $node->getExpectedLabels());
        self::assertSame(['3'], $node->getActualLabels());
        self::assertSame([true, false, true], array_map(fn ($c) => $c->getResult(), $node->getChildren()));
        self::assertStringContainsString('for a subselection of items in cart', $node->getDescription());
    }

    public function testProductConditionCategoryIdsAndQuoteItemAttribute(): void
    {
        $this->categoryList = $this->createStub(ProductCategoryList::class);
        $this->categoryList->method('getCategoryIds')->willReturn([5, 7]);
        $item = $this->item(1, 'A', ['qty' => 2]);

        $tree = $this->walker()->walkActions(
            $this->rule($this->combine('all', true, []), $this->productCombine('all', true, [
                $this->productLeaf('category_ids', '()', '3'),
                $this->productLeaf('quote_item_qty', '>=', '2'),
            ])),
            $item
        );

        [$category, $qty] = $tree->getChildren();
        self::assertFalse($category->getResult());
        self::assertSame([5, 7], $category->getActual());
        self::assertSame(['3'], $category->getExpected());
        self::assertSame(ValueLabels::INPUT_CATEGORY, $category->getInputType());
        self::assertSame(['#3'], $category->getExpectedLabels());
        self::assertSame(['#5', '#7'], $category->getActualLabels());
        self::assertSame('Category is one of #3', $category->getText());
        self::assertSame(['2'], $qty->getActualLabels());
        self::assertSame($qty->getDescription(), $qty->getText(), 'only category nodes rewrite the description');
        self::assertTrue($qty->getResult());
        self::assertEquals(2, $qty->getActual());
        self::assertFalse($tree->getResult());
    }

    public function testProductCombineUsesTheDecidingChildItem(): void
    {
        $child = $this->item(2, 'A-RED');
        $parent = $this->item(1, 'A', [], null, [$child]);

        $tree = $this->walker()->walkActions(
            $this->rule($this->combine('all', true, []), $this->productCombine('all', true, [
                $this->productLeaf('sku', '==', 'A-RED'),
            ])),
            $parent
        );

        self::assertTrue($tree->getResult());
        self::assertSame('A-RED', $tree->getChildren()[0]->getActual());
        self::assertSame('A-RED', $tree->getChildren()[0]->getItem(), 'decided on the child, not the item');
    }

    public function testNodeDecidedOnTheItemItselfCarriesNoItemLabel(): void
    {
        $tree = $this->walker()->walkActions(
            $this->rule($this->combine('all', true, []), $this->productCombine('all', true, [
                $this->productLeaf('sku', '==', 'NONE'),
            ])),
            $this->item(1, 'A', [], null, [$this->item(2, 'A-RED')])
        );

        self::assertNull($tree->getChildren()[0]->getItem());
    }

    public function testFoundFoldsParentAndChildIntoOneItemCheck(): void
    {
        [$parent, $child] = $this->configurable(1, 'MP01', 2, 'MP01-32-Black', ['qty' => 2]);
        $found = $this->found(true, 'all', [
            $this->productLeaf('quote_item_qty', '>=', '1'),
            $this->productLeaf('sku', '==', 'X'),
        ]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], [$parent, $child, $this->item(3, '24-UG06', ['qty' => 1])])
        )->getTree()->getChildren()[0];

        [$folded, $simple] = $node->getChildren();
        self::assertSame(['1--1@1', '1--1@3'], [$folded->getPath(), $simple->getPath()]);
        self::assertSame(['MP01 → MP01-32-Black', '24-UG06'], [$folded->getDescription(), $simple->getDescription()]);
        self::assertSame(['MP01 → MP01-32-Black', '24-UG06'], [$folded->getItem(), $simple->getItem()]);
        self::assertSame([[1, 2], [3]], [$folded->getItemIds(), $simple->getItemIds()]);
        self::assertSame([], $node->getActual());

        [$qty, $sku] = $folded->getChildren();
        self::assertTrue($qty->getResult());
        self::assertNull($qty->getMemberActuals(), 'equal values stay one plain leaf');
        self::assertFalse($sku->getResult());
        self::assertSame(
            [
                ['label' => 'MP01', 'actual' => 'MP01', 'actual_label' => 'MP01', 'result' => false],
                ['label' => 'MP01-32-Black', 'actual' => 'MP01-32-Black', 'actual_label' => 'MP01-32-Black',
                    'result' => false],
            ],
            $sku->getMemberActuals()
        );
        self::assertSame(['MP01', 'MP01-32-Black'], $sku->getActualLabels());
        self::assertSame($sku->getMemberActuals(), $sku->toArray()['member_actuals']);
        self::assertSame([1, 2], $folded->toArray()['item_ids']);
        self::assertSame(
            [$sku->getText() . ' — no item matches: MP01 → MP01-32-Black (MP01, MP01-32-Black), 24-UG06 (24-UG06)'],
            $node->getFailureSummaries()
        );
    }

    public function testFoundFoldsMembersThatAgreeIntoOnePlainLeaf(): void
    {
        [$parent, $child] = $this->configurable(1, 'MP01', 2, 'MP01-32-Black');
        $found = $this->found(true, 'all', [$this->productLeaf('sku', '==', 'MP01-32-Black')]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], [$child, $parent])
        )->getTree()->getChildren()[0];

        self::assertTrue($node->getResult());
        self::assertSame([1, 2], $node->getActual(), 'the parent matches through its child, like core');
        $folded = $node->getChildren()[0];
        self::assertTrue($folded->getResult());
        self::assertSame([1, 2], $folded->getItemIds(), 'the visible item comes first');
        self::assertCount(1, $folded->getChildren());
        self::assertSame('MP01-32-Black', $folded->getChildren()[0]->getActual());
        self::assertNull($folded->getChildren()[0]->getMemberActuals());
    }

    public function testNotFoundFailsWhenAChildMatches(): void
    {
        [$parent, $child] = $this->configurable(1, 'MP01', 2, 'MP01-32-Black');
        $found = $this->found(false, 'all', [$this->productLeaf('sku', '==', 'MP01-32-Black')]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], [$parent, $child])
        )->getTree()->getChildren()[0];

        self::assertFalse($node->getResult());
        self::assertSame([2], $node->getActual());
        self::assertCount(1, $node->getChildren());
        self::assertFalse($node->getChildren()[0]->getResult());
        self::assertSame(
            [$node->getChildren()[0]->getChildren()[0]->getText()
                . ' — found: MP01 → MP01-32-Black (MP01, MP01-32-Black)'],
            $node->getFailureSummaries()
        );
    }

    public function testFoundFoldsNestedCombineWhoseItemsDisagree(): void
    {
        [$parent, $child] = $this->configurable(1, 'MP01', 2, 'MP01-32-Black');
        $found = $this->found(true, 'all', [
            $this->productCombine('all', true, [$this->productLeaf('sku', '==', 'MP01')]),
        ]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], [$parent, $child])
        )->getTree()->getChildren()[0];

        $nested = $node->getChildren()[0]->getChildren()[0];
        self::assertTrue($node->getResult());
        self::assertTrue($nested->getResult(), 'a folded group is true when any item makes it true');
        self::assertNull($nested->getMemberActuals());
        self::assertSame([true, false], array_column($nested->getChildren()[0]->getMemberActuals(), 'result'));
    }

    public function testFoundGroupsBundleChildrenUnderTheBundle(): void
    {
        $children = [$this->item(2, 'C1'), $this->item(3, 'C2')];
        $bundle = $this->item(1, 'BNDL', [], null, $children);
        foreach ($children as $c) {
            $c->setParentItem($bundle);
        }
        $found = $this->found(true, 'all', [$this->productLeaf('sku', '==', 'X')]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], [$bundle, ...$children])
        )->getTree()->getChildren()[0];

        self::assertCount(1, $node->getChildren());
        self::assertSame('BNDL', $node->getChildren()[0]->getDescription());
        self::assertSame([1, 2, 3], $node->getChildren()[0]->getItemIds());
        self::assertSame(
            ['BNDL', 'C1', 'C2'],
            array_column($node->getChildren()[0]->getChildren()[0]->getMemberActuals(), 'label')
        );
    }

    public function testNotFoundBlamesTheMatchingBundleChildNotTheFirstChild(): void
    {
        $found = $this->found(false, 'all', [$this->productLeaf('sku', '==', 'C2')]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], $this->bundle())
        )->getTree()->getChildren()[0];

        self::assertFalse($node->getResult());
        self::assertSame([3], $node->getActual());
        $leaf = $node->getChildren()[0]->getChildren()[0];
        self::assertTrue($leaf->getResult());
        self::assertNull($leaf->getItem(), 'the deciding child itself, not BNDL → C1');
        self::assertSame([$leaf->getText() . ' — found: BNDL (C1, C2)'], $node->getFailureSummaries());
    }

    public function testNotFoundKeepsFailuresUnderANestedFalseCombine(): void
    {
        $found = $this->found(false, 'all', [
            $this->productCombine('all', false, [$this->productLeaf('sku', '==', 'C2')]),
        ]);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$found])),
            $this->address([], $this->bundle())
        )->getTree()->getChildren()[0];

        self::assertFalse($node->getResult());
        self::assertSame([2], $node->getActual());
        $nested = $node->getChildren()[0]->getChildren()[0];
        self::assertTrue($nested->getResult(), 'C1 decides: it makes the FALSE combine true');
        self::assertFalse($nested->getChildren()[0]->getResult());
        self::assertSame(
            [$nested->getChildren()[0]],
            array_column($node->getFailingChecks(), 'leaf')
        );
    }

    public function testMultishippingSubselectGroupsItemsByVisibleItem(): void
    {
        [$parent, $child] = $this->configurable(1, 'MP01', 2, 'MP01-32-Black', ['qty' => 2]);
        $subselect = $this->subselect('qty', '>=', '1', [$this->productLeaf('sku', '==', 'MP01')]);
        $address = $this->address([], [$parent, $child]);
        $address->getQuote()->setIsMultiShipping(true);

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$subselect])),
            $address
        )->getTree()->getChildren()[0];

        self::assertCount(1, $node->getChildren());
        self::assertSame([1, 2], $node->getChildren()[0]->getItemIds());
        self::assertTrue($node->getChildren()[0]->getResult());
        self::assertSame(
            [true, false],
            array_column($node->getChildren()[0]->getChildren()[0]->getMemberActuals(), 'result')
        );
    }

    public function testAddressOptionValuesGetOptionLabels(): void
    {
        $countries = $this->createStub(Country::class);
        $countries->method('toOptionArray')->willReturn([
            ['value' => 'US', 'label' => 'United States'],
            ['value' => 'DE', 'label' => 'Germany'],
        ]);
        $args = $this->addressConditionArgs();
        $args[1] = $countries;
        $leaf = $this->configure(new AddressCondition(...$args), 'country_id', '==', 'US');
        $leaf->capture = $this->plugin;

        $node = $this->walker()->walkConditions(
            $this->rule($this->combine('all', true, [$leaf])),
            $this->address(['country_id' => 'DE'])
        )->getTree()->getChildren()[0];

        self::assertSame(['United States'], $node->getExpectedLabels());
        self::assertSame(['Germany'], $node->getActualLabels());
        self::assertSame('select', $node->getInputType());
    }

    public function testUnknownNodeGetsResultOnly(): void
    {
        $vendor = new VendorCondition($this->createStub(Context::class));
        $broken = new VendorCondition($this->createStub(Context::class));
        $broken->verdict = false;
        $broken->brokenDescription = true;

        $tree = $this->walker()->walkConditions(
            $this->rule($this->combine('any', true, [$vendor, $broken])),
            $this->address([])
        )->getTree();

        [$ok, $failed] = $tree->getChildren();
        self::assertTrue($ok->getResult());
        self::assertSame('Customer is a VIP', $ok->getDescription());
        self::assertNull($ok->getExpected());
        self::assertNull($ok->getActual());
        self::assertSame('No explanation available for ' . VendorCondition::class . '.', $ok->getNote());
        self::assertFalse($failed->getResult());
        self::assertSame(VendorCondition::class, $failed->getDescription());
        self::assertTrue($tree->getResult());
    }

    public function testDisagreementBecomesWarning(): void
    {
        $rule = $this->rule($this->combine('all', true, [$this->addressLeaf('total_qty', '>=', '1')]), null, false);

        $result = $this->walker()->walkConditions($rule, $this->address(['total_qty' => 3]));

        self::assertTrue($result->getTree()->getResult());
        self::assertFalse($result->isValid());
        self::assertSame(ConditionWalker::WARNING_CODE, $result->getWarning()->getCode());
        self::assertSame(['rule_id' => 7], $result->getWarning()->getContext());
        self::assertStringContainsString('Rule "Spring"', $result->getWarning()->getMessage());
    }

    public function testItemRestrictedRuleSeesEligibleTotalsOnly(): void
    {
        $items = [
            $this->item(1, 'A', ['base_row_total' => 40, 'qty' => 1]),
            $this->item(2, 'B', ['base_row_total' => 60, 'qty' => 1]),
        ];
        $address = $this->address(['base_subtotal' => 100, 'total_qty' => 2], $items);
        $rule = $this->rule(
            $this->combine('all', true, [$this->addressLeaf('base_subtotal', '>=', '50')]),
            $this->productCombine('all', true, [$this->productLeaf('sku', '==', 'A')])
        );

        $result = $this->walker()->walkConditions($rule, $address);

        self::assertFalse($result->isValid());
        self::assertEquals(40, $result->getTree()->getChildren()[0]->getActual());
        self::assertSame(100, $address->getBaseSubtotal());
        self::assertSame(2, $address->getTotalQty());
    }

    public function testEligibleTotalsSkipConfigurableChildrenCalculatedBundleAndNoDiscountItems(): void
    {
        $parent = $this->item(10, 'CONF');
        $parent->getProduct()->setTypeId('configurable');
        $child = $this->item(11, 'A', ['base_row_total' => 50, 'qty' => 1]);
        $child->setParentItem($parent);
        $bundle = $this->item(12, 'A', ['base_row_total' => 70, 'qty' => 1, 'has_children' => true]);
        $bundle->getProduct()->setData('price_type', 0);
        $items = [
            $this->item(1, 'A', [
                'base_row_total' => 40,
                'base_row_total_incl_tax' => 44,
                'qty' => 1,
                'row_weight' => 2,
            ]),
            $child,
            $bundle,
            $this->item(13, 'A', ['base_row_total' => 90, 'qty' => 1, 'no_discount' => 1]),
        ];
        $totals = [
            'base_subtotal' => 300,
            'base_subtotal_with_discount' => 280,
            'base_subtotal_total_incl_tax' => 330,
            'total_qty' => 4,
            'weight' => 9,
        ];
        $address = $this->address($totals, $items);
        $leaves = array_map(fn (string $code) => $this->addressLeaf($code, '>=', '0'), array_keys($totals));
        $rule = $this->rule(
            $this->combine('all', true, $leaves),
            $this->productCombine('all', true, [$this->productLeaf('sku', '==', 'A')])
        );

        $tree = $this->walker()->walkConditions($rule, $address)->getTree();

        self::assertEquals(
            [40, 40, 44, 1, 2],
            array_map(fn (ConditionNode $n) => $n->getActual(), $tree->getChildren())
        );
        self::assertSame($totals, array_intersect_key($address->getData(), $totals));
    }

    /**
     * @return array<string, array{string|null, string[]}>
     */
    public static function attributeScopeProvider(): array
    {
        return [
            'parent' => ['parent', ['P']],
            'children' => ['children', ['C1', 'C2']],
            'default: children then the item' => [null, ['C1', 'C2', 'P']],
        ];
    }

    /**
     * @param string|null $scope
     * @param string[] $checked SKUs the leaf is evaluated against, in order
     */
    #[DataProvider('attributeScopeProvider')]
    public function testActionsLeafIsEvaluatedPerAttributeScope(?string $scope, array $checked): void
    {
        $children = [$this->item(2, 'C1'), $this->item(3, 'C2')];
        $item = $this->item(1, 'P', [], null, $children);
        foreach ($children as $child) {
            $child->setParentItem($item);
        }
        $leaf = $this->productLeaf('sku', '==', 'NONE');
        $leaf->setAttributeScope($scope);

        $node = $this->walker()->walkActions(
            $this->rule($this->combine('all', true, []), $this->productCombine('all', true, [$leaf])),
            $scope === 'parent' ? $children[0] : $item
        )->getChildren()[0];

        self::assertFalse($node->getResult());
        self::assertSame(end($checked), $node->getActual(), 'the last checked entity is reported');
    }

    public function testNoEligibleItemKeepsAddressTotals(): void
    {
        $address = $this->address(['base_subtotal' => 100], [$this->item(1, 'B', ['base_row_total' => 100])]);
        $rule = $this->rule(
            $this->combine('all', true, [$this->addressLeaf('base_subtotal', '>=', '50')]),
            $this->productCombine('all', true, [$this->productLeaf('sku', '==', 'A')])
        );

        $result = $this->walker()->walkConditions($rule, $address);

        self::assertTrue($result->isValid());
        self::assertSame(100, $result->getTree()->getChildren()[0]->getActual());
    }

    public function testEndsCaptureOnException(): void
    {
        $rule = $this->createPartialMock(Rule::class, ['getConditions', 'getActions', 'validate']);
        $rule->method('getConditions')->willReturn($this->combine('all', true, []));
        $rule->method('getActions')->willReturn($this->productCombine('all', true, []));
        $rule->method('validate')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->walker()->walkConditions($rule, $this->address([]));
            self::fail('Exception expected.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertFalse($this->tracer->isCapturing());
    }

    /**
     * Configurable parent with its one chosen child.
     *
     * @param int $parentId
     * @param string $parentSku
     * @param int $childId
     * @param string $childSku
     * @param array<string, mixed> $data
     * @return Item[]
     */
    private function configurable(
        int $parentId,
        string $parentSku,
        int $childId,
        string $childSku,
        array $data = []
    ): array {
        $child = $this->item($childId, $childSku, $data);
        $parent = $this->item($parentId, $parentSku, $data, null, [$child]);
        $child->setParentItem($parent);

        return [$parent, $child];
    }

    /**
     * Bundle BNDL (#1) with children C1 (#2) and C2 (#3), as getAllItems() lists them.
     *
     * @return Item[]
     */
    private function bundle(): array
    {
        $children = [$this->item(2, 'C1'), $this->item(3, 'C2')];
        $bundle = $this->item(1, 'BNDL', [], null, $children);
        foreach ($children as $c) {
            $c->setParentItem($bundle);
        }

        return [$bundle, ...$children];
    }

    private function walker(): ConditionWalker
    {
        return new ConditionWalker($this->tracer, new ValueLabels(), new ItemLabel());
    }

    /**
     * @param Combine $conditions
     * @param ProductCombine|null $actions
     * @param bool|null $verdict null = real validate()
     * @return Rule&\PHPUnit\Framework\MockObject\MockObject
     */
    private function rule(Combine $conditions, ?ProductCombine $actions = null, ?bool $verdict = null): Rule
    {
        $methods = ['getConditions', 'getActions'];
        if ($verdict !== null) {
            $methods[] = 'validate';
        }
        $rule = $this->createPartialMock(Rule::class, $methods);
        $rule->method('getConditions')->willReturn($conditions);
        $rule->method('getActions')->willReturn($actions ?? $this->productCombine('all', true, []));
        if ($verdict !== null) {
            $rule->method('validate')->willReturn($verdict);
        }
        $rule->setId(7);
        $rule->setName('Spring');

        return $rule;
    }

    /**
     * @param array<string, mixed> $data
     * @param Item[] $items
     * @param Item[] $visibleItems
     * @return Address
     */
    private function address(array $data, array $items = [], array $visibleItems = []): Address
    {
        $quote = $this->createPartialMock(Quote::class, ['getAllVisibleItems']);
        $quote->method('getAllVisibleItems')->willReturn($visibleItems);
        $address = $this->createPartialMock(Address::class, ['getAllItems', 'getQuote']);
        $address->method('getAllItems')->willReturn($items);
        $address->method('getQuote')->willReturn($quote);
        $address->setData($data);

        return $address;
    }

    /**
     * @param int $id
     * @param string $sku
     * @param array<string, mixed> $data
     * @param Quote|null $quote
     * @param Item[] $children
     * @return Item
     */
    private function item(int $id, string $sku, array $data = [], ?Quote $quote = null, array $children = []): Item
    {
        $product = (new \ReflectionClass(CatalogProduct::class))->newInstanceWithoutConstructor();
        $product->resource = $this->createStub(ProductResource::class);
        $product->setData(['entity_id' => $id, 'sku' => $sku]);
        $item = $this->createPartialMock(Item::class, ['getQuote', 'getChildren']);
        $item->method('getQuote')->willReturn($quote);
        $item->method('getChildren')->willReturn($children);
        $item->setData(['id' => $id, 'item_id' => $id, 'sku' => $sku, 'product' => $product] + $data);

        return $item;
    }

    /**
     * @param string $aggregator
     * @param bool $value
     * @param AbstractCondition[] $children
     * @return Combine
     */
    private function combine(string $aggregator, bool $value, array $children): Combine
    {
        $combine = new Combine(
            $this->createStub(Context::class),
            $this->createStub(ManagerInterface::class),
            $this->coreAddressLeaf('base_subtotal', '>=', '0')
        );

        return $this->fill($combine, $aggregator, $value, $children);
    }

    /**
     * @param string $aggregator
     * @param bool $value
     * @param AbstractCondition[] $children
     * @return ProductCombine
     */
    private function productCombine(string $aggregator, bool $value, array $children): ProductCombine
    {
        return $this->fill(
            new ProductCombine($this->createStub(Context::class), $this->productLeaf('sku', '==', '')),
            $aggregator,
            $value,
            $children
        );
    }

    /**
     * @param bool $value
     * @param string $aggregator
     * @param AbstractCondition[] $children
     * @return Found
     */
    private function found(bool $value, string $aggregator, array $children): Found
    {
        return $this->fill(
            new Found($this->createStub(Context::class), $this->productLeaf('sku', '==', '')),
            $aggregator,
            $value,
            $children
        );
    }

    /**
     * @param string $attribute
     * @param string $operator
     * @param string $value
     * @param AbstractCondition[] $children
     * @return SubselectCondition
     */
    private function subselect(string $attribute, string $operator, string $value, array $children): SubselectCondition
    {
        $node = new SubselectCondition($this->createStub(Context::class), $this->productLeaf('sku', '==', ''));
        $node->capture = $this->plugin;
        $node->addData(
            ['attribute' => $attribute, 'operator' => $operator, 'aggregator' => 'all', 'value' => $value]
        );
        $node->setConditions($children);

        return $node;
    }

    /**
     * @template T of \Magento\Rule\Model\Condition\Combine
     * @param T $combine
     * @param string $aggregator
     * @param bool $value
     * @param AbstractCondition[] $children
     * @return T
     */
    private function fill($combine, string $aggregator, bool $value, array $children)
    {
        $combine->addData(['aggregator' => $aggregator, 'value' => $value]);
        $combine->setConditions($children);

        return $combine;
    }

    private function addressLeaf(string $attribute, string $operator, string $value): AddressCondition
    {
        $leaf = new AddressCondition(...$this->addressConditionArgs());
        $leaf->capture = $this->plugin;

        return $this->configure($leaf, $attribute, $operator, $value);
    }

    private function coreAddressLeaf(string $attribute, string $operator, string $value): CoreAddressCondition
    {
        return $this->configure(
            new CoreAddressCondition(...$this->addressConditionArgs()),
            $attribute,
            $operator,
            $value
        );
    }

    private function productLeaf(string $attribute, string $operator, string $value): ProductCondition
    {
        $resource = $this->createMock(ProductResource::class);
        $resource->method('loadAllAttributes')->willReturnSelf();
        $resource->method('getAttributesByCode')->willReturn([]);
        $leaf = new ProductCondition(
            $this->createStub(Context::class),
            $this->createStub(BackendHelper::class),
            $this->createStub(EavConfig::class),
            $this->createStub(ProductFactory::class),
            $this->createStub(ProductRepositoryInterface::class),
            $resource,
            $this->createStub(AttributeSetCollection::class),
            $this->createStub(FormatInterface::class),
            [],
            $this->categoryList
        );
        $leaf->capture = $this->plugin;

        return $this->configure($leaf, $attribute, $operator, $value);
    }

    /**
     * @return array<int, mixed>
     */
    private function addressConditionArgs(): array
    {
        return [
            $this->createStub(Context::class),
            $this->createStub(Country::class),
            $this->createStub(Allregion::class),
            $this->createStub(ShippingMethods::class),
            $this->createStub(PaymentMethods::class),
        ];
    }

    /**
     * @template T of AbstractCondition
     * @param T $leaf
     * @param string $attribute
     * @param string $operator
     * @param string $value
     * @return T
     */
    private function configure($leaf, string $attribute, string $operator, string $value)
    {
        $leaf->addData(['attribute' => $attribute, 'operator' => $operator, 'value' => $value]);

        return $leaf;
    }
}
