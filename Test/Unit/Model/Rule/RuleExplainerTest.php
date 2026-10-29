<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Rule;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\Warning;
use DmLab\PriceExplain\Model\Rule\Candidate;
use DmLab\PriceExplain\Model\Rule\CategoryLabels;
use DmLab\PriceExplain\Model\Rule\ConditionsResult;
use DmLab\PriceExplain\Model\Rule\ConditionWalker;
use DmLab\PriceExplain\Model\Rule\RuleExplainer;
use DmLab\PriceExplain\Model\Rule\RulesExplanation;
use DmLab\PriceExplain\Model\Rule\ValueLabels;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\SalesRule\Api\Data\DiscountAppliedToInterface;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use Magento\SalesRule\Model\Data\DiscountData;
use Magento\SalesRule\Model\Quote\ChildrenValidationLocator;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RuleExplainerTest extends TestCase
{
    /**
     * @var array<int, bool|\Closure> rule ID => conditions verdict, or fn (float $baseSubtotalWithDiscount): bool
     */
    private array $conditions = [];

    /**
     * @var array<int, array<int, bool>> rule ID => item ID => actions result (default true)
     */
    private array $actions = [];

    /**
     * @var int[] item IDs a discount validator rejects
     */
    private array $invalidItems = [];

    /**
     * @var bool
     */
    private bool $childrenValidation = true;

    /**
     * @var array<int, true> object IDs of the items built by item(); any other item is a clone
     */
    private array $built = [];

    /**
     * @var array<int, float> rule ID => base_subtotal_with_discount seen by walkConditions()
     */
    private array $seenSubtotals = [];

    public function testStackingWithStopFlagAndAmountsMappedToRules(): void
    {
        $stopper = $this->rule(1, ['stop_rules_processing' => 1, 'sort_order' => 1]);
        $other = $this->rule(2, ['sort_order' => 2]);
        $this->actions[1] = [11 => true, 12 => false];
        $items = [
            $this->item(11, 'A', ['applied_rule_ids' => '1'], [1 => 5.0]),
            $this->item(12, 'B', ['applied_rule_ids' => '2'], [2 => 3.0]),
        ];

        $result = $this->explain($items, [new Candidate($stopper), new Candidate($other)]);
        [$first, $second] = $result->getVerdicts();

        self::assertSame(Status::APPLIED, $first->getStatus());
        self::assertTrue($first->isStopRulesProcessing());
        self::assertSame(-5.0, $first->getAmount());
        self::assertSame([true, false], $this->applied($first));
        self::assertSame(Stage::ACTIONS, $first->getItems()[1]->getStage());
        self::assertFalse($first->getItems()[1]->getActions()->getResult());

        self::assertSame(Status::APPLIED, $second->getStatus());
        self::assertSame(-3.0, $second->getAmount());
        self::assertSame(Stage::STOPPED_BY, $second->getItems()[0]->getStage());
        self::assertSame(1, $second->getItems()[0]->getStoppedBy());
        self::assertSame(-3.0, $second->getItems()[1]->getAmount());
        self::assertSame([], $result->getWarnings());
    }

    public function testConditionsSeeSubtotalMinusEarlierRulesOnly(): void
    {
        $items = [$this->item(11, 'A', ['applied_rule_ids' => '1,2'], [1 => 10.0, 2 => 5.0])];
        $quote = $this->quote($items, false, [], ['base_subtotal' => 100.0, 'base_subtotal_with_discount' => 85.0]);
        $candidates = array_map(fn (int $id): Candidate => new Candidate($this->rule($id, [])), [1, 2, 3]);

        $this->explainer($this->walker())->explain($quote, $candidates);

        self::assertSame([1 => 100.0, 2 => 90.0, 3 => 85.0], $this->seenSubtotals);
        self::assertSame(85.0, $quote->getShippingAddress()->getData('base_subtotal_with_discount'));
    }

    public function testCartFixedConditionsSeeFullSubtotalOnSavedAddress(): void
    {
        $items = [$this->item(11, 'A', ['applied_rule_ids' => '1,2'], [1 => 10.0, 2 => 5.0])];
        $candidates = [
            new Candidate($this->rule(1, [])),
            new Candidate($this->rule(2, ['simple_action' => Rule::CART_FIXED_ACTION])),
            new Candidate($this->rule(3, [])),
        ];

        $saved = $this->quote($items, false, [], ['id' => 7, 'base_subtotal' => 100.0]);
        $this->explainer($this->walker())->explain($saved, $candidates);
        self::assertSame([1 => 100.0, 2 => 100.0, 3 => 85.0], $this->seenSubtotals);

        $this->seenSubtotals = [];
        $unsaved = $this->quote($items, false, [], ['base_subtotal' => 100.0]);
        $this->explainer($this->walker())->explain($unsaved, $candidates);
        self::assertSame([1 => 100.0, 2 => 90.0, 3 => 85.0], $this->seenSubtotals);
    }

    public function testStopFlagOnRejectedItemDoesNotStopIt(): void
    {
        $stopper = $this->rule(1, ['stop_rules_processing' => 1]);
        $this->actions[1] = [11 => false];
        $items = [$this->item(11, 'A', ['applied_rule_ids' => '2'])];

        $result = $this->explain($items, [new Candidate($stopper), new Candidate($this->rule(2, []))]);

        self::assertSame(Status::REJECTED, $result->getVerdicts()[0]->getStatus());
        self::assertSame(Stage::ACTIONS, $result->getVerdicts()[0]->getStage());
        self::assertSame([true], $this->applied($result->getVerdicts()[1]));
    }

    /**
     * @return array<string, array{string, float, float, bool}>
     */
    public static function discountStepProvider(): array
    {
        return [
            'percent: step equals qty applies' => [Rule::BY_PERCENT_ACTION, 2, 2, true],
            'percent: step above qty skips' => [Rule::BY_PERCENT_ACTION, 3, 2, false],
            'fixed: step above qty skips' => [Rule::BY_FIXED_ACTION, 3, 2, false],
            'fixed: step below qty applies' => [Rule::BY_FIXED_ACTION, 1, 2, true],
            'buy x get y: step equals qty skips' => [Rule::BUY_X_GET_Y_ACTION, 2, 2, false],
            'buy x get y: step below qty applies' => [Rule::BUY_X_GET_Y_ACTION, 1, 2, true],
            'cart fixed: step is ignored' => [Rule::CART_FIXED_ACTION, 5, 1, true],
        ];
    }

    /**
     * @param string $action
     * @param float $step
     * @param float $qty
     * @param bool $applies
     */
    #[DataProvider('discountStepProvider')]
    public function testDiscountStepPerActionType(string $action, float $step, float $qty, bool $applies): void
    {
        $rule = $this->rule(1, ['simple_action' => $action, 'discount_step' => $step]);
        $items = [$this->item(11, 'A', ['qty' => $qty, 'applied_rule_ids' => $applies ? '1' : ''])];

        $result = $this->explain($items, [new Candidate($rule)]);
        $outcome = $result->getVerdicts()[0]->getItems()[0];

        self::assertSame($applies, $outcome->isApplied());
        self::assertSame($applies ? null : Stage::DISCOUNT_STEP, $outcome->getStage());
        self::assertSame($applies ? Status::APPLIED : Status::REJECTED, $result->getVerdicts()[0]->getStatus());
        self::assertSame([], $result->getWarnings());
    }

    public function testDiscountQtyLimitIsNotedOnAppliedItem(): void
    {
        $rule = $this->rule(1, ['discount_qty' => 2]);
        $items = [
            $this->item(11, 'A', ['qty' => 5, 'applied_rule_ids' => '1'], [1 => 4.0]),
            $this->item(12, 'B', ['qty' => 1, 'applied_rule_ids' => '1'], [1 => 2.0]),
        ];

        [$limited, $full] = $this->explain($items, [new Candidate($rule)])->getVerdicts()[0]->getItems();

        self::assertTrue($limited->isApplied());
        self::assertSame(Stage::DISCOUNT_QTY, $limited->getStage());
        self::assertStringContainsString('2 of 5', (string)$limited->getReason());
        self::assertNull($full->getStage());
    }

    public function testConfigurableChildIsFoldedUnderParent(): void
    {
        $parent = $this->item(10, 'MP01-32-Black', ['applied_rule_ids' => '1', 'product' => $this->product('MP01')], [
            1 => 1.0,
        ]);
        $child = $this->item(11, 'MP01-32-Black', [], [], $parent);
        $simple = $this->item(12, '24-MB01', ['applied_rule_ids' => '1'], [1 => 2.0]);

        $result = $this->explain([$parent, $child, $simple], [new Candidate($this->rule(1, []))]);
        $verdict = $result->getVerdicts()[0];

        self::assertSame(Status::APPLIED, $verdict->getStatus());
        self::assertSame(-3.0, $verdict->getAmount());
        self::assertSame(
            [['MP01 → MP01-32-Black', true], ['24-MB01', true]],
            array_map(fn (RuleItemOutcome $o) => [$o->getLabel(), $o->isApplied()], $verdict->getItems())
        );
        self::assertSame([[], []], array_map(fn (RuleItemOutcome $o) => $o->getChildren(), $verdict->getItems()));
        self::assertSame([], $result->getWarnings());
    }

    public function testGenuinelyExcludedItems(): void
    {
        $flagged = $this->item(12, 'NODISC', ['no_discount' => 1]);
        $invalid = $this->item(13, 'INVALID');
        $this->invalidItems = [13];

        $verdict = $this->explain(
            [$this->item(11, 'A', ['applied_rule_ids' => '1'], [1 => 1.0]), $flagged, $invalid],
            [new Candidate($this->rule(1, []))]
        )->getVerdicts()[0];

        self::assertSame(Status::APPLIED, $verdict->getStatus());
        self::assertSame(
            [[true, null], [false, Stage::ITEM_EXCLUDED], [false, Stage::ITEM_EXCLUDED]],
            array_map(fn (RuleItemOutcome $o) => [$o->isApplied(), $o->getStage()], $verdict->getItems())
        );
        self::assertStringContainsString('no discount', (string)$verdict->getItems()[1]->getReason());
        self::assertStringContainsString('validator', (string)$verdict->getItems()[2]->getReason());
        self::assertSame('NODISC', $verdict->getItems()[1]->getLabel());
    }

    public function testOnlyExcludedItemsGiveExclusionVerdict(): void
    {
        $verdict = $this->explain(
            [$this->item(12, 'NODISC', ['no_discount' => 1])],
            [new Candidate($this->rule(1, []))]
        )->getVerdicts()[0];

        self::assertSame([Status::REJECTED, Stage::ITEM_EXCLUDED], [$verdict->getStatus(), $verdict->getStage()]);
        self::assertStringContainsString('no discount', (string)$verdict->getReason());
    }

    public function testDynamicBundleDiscountedPerChildKeepsChildRows(): void
    {
        $bundle = $this->item(20, 'BNDL-A-B', ['applied_rule_ids' => '1', 'product' => $this->product('BNDL', 0)], [
            1 => 4.0,
        ]);
        $first = $this->item(21, 'A', [], [1 => 4.0], $bundle);
        $second = $this->item(22, 'B', [], [], $bundle);
        // 0 = the ID-less clone core validates the bundle itself with.
        $this->actions[1] = [0 => false, 21 => true, 22 => false];
        $this->childrenValidation = false;

        $result = $this->explain([$bundle, $first, $second], [new Candidate($this->rule(1, []))]);
        $outcomes = $result->getVerdicts()[0]->getItems();

        self::assertCount(1, $outcomes);
        self::assertSame('BNDL', $outcomes[0]->getLabel());
        self::assertSame(-4.0, $outcomes[0]->getAmount());
        self::assertSame(
            [['BNDL → A', true, null, -4.0], ['BNDL → B', false, Stage::ACTIONS, null]],
            array_map(
                fn (RuleItemOutcome $o) => [$o->getLabel(), $o->isApplied(), $o->getStage(), $o->getAmount()],
                $outcomes[0]->getChildren()
            )
        );
        self::assertSame('1--22', $outcomes[0]->getChildren()[1]->getActions()->getPath());
        self::assertSame([], $result->getWarnings());
    }

    public function testBundleDiscountDistributedFromParentHasNoChildRows(): void
    {
        $bundle = $this->item(20, 'BNDL', ['applied_rule_ids' => '1', 'product' => $this->product('BNDL', 0)], [
            1 => 4.0,
        ]);
        $this->item(21, 'A', [], [1 => 4.0], $bundle);

        $outcome = $this->explain([$bundle], [new Candidate($this->rule(1, []))])->getVerdicts()[0]->getItems()[0];

        self::assertTrue($outcome->isApplied());
        self::assertSame([], $outcome->getChildren());
    }

    public function testFixedBundleIsNotCalculatedPerChild(): void
    {
        $bundle = $this->item(20, 'BNDL', ['applied_rule_ids' => '1', 'product' => $this->product('BNDL', 1)]);
        $this->item(21, 'A', [], [], $bundle);
        $this->actions[1] = [0 => false];

        $outcome = $this->explain([$bundle], [new Candidate($this->rule(1, []))])->getVerdicts()[0]->getItems()[0];

        self::assertSame([], $outcome->getChildren());
    }

    public function testRuleRejectedWithMixedStagesReportsFirstNonExclusionStage(): void
    {
        $this->actions[1] = [11 => false];
        $verdict = $this->explain(
            [$this->item(10, 'NODISC', ['no_discount' => 1]), $this->item(11, 'A')],
            [new Candidate($this->rule(1, []))]
        )->getVerdicts()[0];

        self::assertSame(Status::REJECTED, $verdict->getStatus());
        self::assertSame(Stage::ACTIONS, $verdict->getStage());
        self::assertStringContainsString('see the items', (string)$verdict->getReason());
        self::assertNull($verdict->getAmount());
    }

    public function testMatchingChildCountsWhenChildrenValidationIsRequired(): void
    {
        $child = $this->item(11, 'CONF-RED');
        $parent = $this->item(10, 'CONF', ['applied_rule_ids' => '1']);
        $child->setParentItem($parent);
        $this->actions[1] = [10 => false, 11 => true];

        $outcome = $this->explain([$parent], [new Candidate($this->rule(1, []))])->getVerdicts()[0]->getItems()[0];
        self::assertTrue($outcome->isApplied());
        self::assertSame('1--11', $outcome->getActions()->getPath());

        $this->childrenValidation = false;
        $parent->setAppliedRuleIds('');
        $outcome = $this->explain([$parent], [new Candidate($this->rule(1, []))])->getVerdicts()[0]->getItems()[0];
        self::assertFalse($outcome->isApplied());
        self::assertSame(Stage::ACTIONS, $outcome->getStage());
    }

    public function testPreFilterAndConditionRejections(): void
    {
        $this->conditions[3] = false;
        $items = [$this->item(11, 'A')];
        $candidates = [
            new Candidate($this->rule(1, []), Stage::WEBSITE, 'Other website.'),
            new Candidate($this->rule(2, []), Stage::COUPON, 'Coupon required.'),
            new Candidate($this->rule(3, ['name' => 'Free over 100'])),
        ];

        [$website, $coupon, $conditions] = $this->explain($items, $candidates)->getVerdicts();

        self::assertSame([Status::NOT_CANDIDATE, Stage::WEBSITE], [$website->getStatus(), $website->getStage()]);
        self::assertSame([Status::REJECTED, Stage::COUPON], [$coupon->getStatus(), $coupon->getStage()]);
        self::assertSame('Coupon required.', $coupon->getReason());
        self::assertSame([], $coupon->getItems());
        self::assertSame([Status::REJECTED, Stage::CONDITIONS], [$conditions->getStatus(), $conditions->getStage()]);
        self::assertSame('Conditions are not met: Subtotal >= 100.', $conditions->getReason());
        self::assertFalse($conditions->getConditions()->getResult());
        self::assertSame([], $conditions->getItems());
    }

    public function testConditionsReasonNamesCategoriesAndListsEachConditionOnce(): void
    {
        $category = static fn (string $path, array $actual): ConditionNode => new ConditionNode(
            $path,
            'Category is one of 25,16',
            false,
            true,
            null,
            ['25', '16'],
            $actual,
            [],
            null,
            null,
            'Category is one of',
            ['#25', '#16'],
            array_map(static fn (int $id): string => '#' . $id, $actual),
            ValueLabels::INPUT_CATEGORY
        );
        $check = static fn (int $id, string $label, array $actual): ConditionNode => new ConditionNode(
            '1--1@' . $id,
            'Item #' . $id,
            false,
            true,
            'all',
            true,
            null,
            [$category('1--1--1', $actual)],
            null,
            $label
        );
        $tree = new ConditionNode('1', 'If ALL', false, true, 'all', true, null, [
            new ConditionNode('1--1', 'If an item is FOUND', false, true, 'all', null, [], [
                $check(1, 'MP01', [18]),
                $check(2, '24-UG06', [40]),
            ]),
        ]);
        $walker = $this->createStub(ConditionWalker::class);
        $walker->method('walkConditions')->willReturn(new ConditionsResult($tree, false));
        $collection = $this->getMockBuilder(CategoryCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setStoreId', 'addAttributeToSelect', 'addIdFilter', 'getIterator'])
            ->getMock();
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['id' => 25, 'name' => 'Pants']),
            new DataObject(['id' => 16, 'name' => 'Tees']),
            new DataObject(['id' => 18, 'name' => 'Pants']),
            new DataObject(['id' => 40, 'name' => 'Gear']),
        ]));
        $factory = $this->createStub(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $verdict = $this->explainer($walker, $factory)
            ->explain($this->quote([$this->item(1, 'MP01')]), [new Candidate($this->rule(3, []))])
            ->getVerdicts()[0];

        self::assertSame(
            'Conditions are not met: Category is one of Pants (#25), Tees — no item matches: MP01 (Pants (#18)), '
            . '24-UG06 (Gear).',
            $verdict->getReason()
        );
        self::assertSame(
            ['Gear'],
            $verdict->getConditions()->getChildren()[0]->getChildren()[1]->getChildren()[0]->getActualLabels()
        );
    }

    public function testWalkerWarningIsPropagated(): void
    {
        $warning = new Warning(ConditionWalker::WARNING_CODE, 'disagree');
        $walker = $this->walker([1 => $warning]);

        $result = $this->explainer($walker)->explain(
            $this->quote([$this->item(11, 'A', ['applied_rule_ids' => '1'])]),
            [new Candidate($this->rule(1, []))]
        );

        self::assertSame([$warning], $result->getWarnings());
    }

    public function testMismatchWithCoreBecomesWarning(): void
    {
        $this->conditions[2] = false;
        $items = [$this->item(11, 'A', ['applied_rule_ids' => '2,9'])];

        $warnings = $this->explain($items, [new Candidate($this->rule(1, [])), new Candidate($this->rule(2, []))])
            ->getWarnings();

        self::assertSame(
            [[1, 11, 'applied', 'not applied'], [2, 11, 'not applied', 'applied'], [9, 11, 'not applied', 'applied']],
            array_map(fn (Warning $w) => [
                $w->getContext()['rule_id'],
                $w->getContext()['item_id'],
                str_contains($w->getMessage(), 'explained: applied') ? 'applied' : 'not applied',
                str_contains($w->getMessage(), 'core: applied') ? 'applied' : 'not applied',
            ], $warnings)
        );
        self::assertSame(RuleExplainer::WARNING_CODE, $warnings[0]->getCode());
        self::assertStringContainsString('third-party plugin or observer', $warnings[0]->getMessage());
        self::assertStringContainsString('"Rule 1"', $warnings[0]->getMessage());
    }

    public function testShippingDiscountAndFreeShipping(): void
    {
        $shippingRule = $this->rule(1, ['simple_free_shipping' => 2]);
        $shippingOnly = $this->rule(2, []);
        $this->actions[2] = [11 => false];
        $items = [$this->item(11, 'A', ['applied_rule_ids' => '1'], [1 => 2.0])];
        $quote = $this->quote($items, false, [
            $this->discount(1, 2.0, DiscountAppliedToInterface::APPLIED_TO_ITEM),
            $this->discount(1, 5.0, DiscountAppliedToInterface::APPLIED_TO_SHIPPING),
            $this->discount(2, 1.5, DiscountAppliedToInterface::APPLIED_TO_SHIPPING),
        ]);

        [$first, $second] = $this->explainer($this->walker())
            ->explain($quote, [new Candidate($shippingRule), new Candidate($shippingOnly)])
            ->getVerdicts();

        self::assertSame(-7.0, $first->getAmount());
        self::assertSame(-5.0, $first->getShippingAmount());
        self::assertTrue($first->isFreeShipping());
        self::assertSame(FreeShipping::MODE_ADDRESS, $first->getFreeShippingMode());
        self::assertSame(Status::APPLIED, $second->getStatus());
        self::assertSame(-1.5, $second->getAmount());
        self::assertFalse($second->isFreeShipping());
        self::assertNull($second->getFreeShippingMode());
    }

    public function testFreeShippingIgnoresDiscountStepAndValidatorsButNotActionsOrNoDiscount(): void
    {
        $rules = [];
        foreach ([1 => 1, 2 => 1, 3 => 2, 4 => 2] as $id => $mode) {
            $rules[] = new Candidate($this->rule($id, ['simple_free_shipping' => $mode, 'discount_step' => 3]));
        }
        $this->actions = [2 => [11 => false, 12 => false], 3 => [11 => false], 4 => [11 => false, 12 => false]];
        $this->invalidItems = [12];
        $items = [$this->item(11, 'A'), $this->item(12, 'B'), $this->item(13, 'C', ['no_discount' => 1])];

        $verdicts = $this->explain($items, $rules)->getVerdicts();

        self::assertSame(
            [Status::REJECTED, Stage::DISCOUNT_STEP],
            [$verdicts[0]->getStatus(), $verdicts[0]->getStage()]
        );
        self::assertSame(
            [FreeShipping::MODE_ITEMS, null, FreeShipping::MODE_ADDRESS, null],
            array_map(fn (RuleVerdict $v): ?string => $v->getFreeShippingMode(), $verdicts)
        );
    }

    public function testFreeShippingStopStateIsIndependentOfDiscountStop(): void
    {
        $stopper = $this->rule(1, ['discount_step' => 3, 'stop_rules_processing' => 1]);
        $this->actions = [1 => [12 => false], 2 => [12 => false], 3 => [11 => false]];
        $items = [$this->item(11, 'A', [], [2 => 1.0]), $this->item(12, 'B', [], [3 => 1.0])];
        $rules = [$stopper];
        foreach ([2, 3] as $id) {
            $rules[] = $this->rule($id, ['simple_free_shipping' => 1]);
        }

        $verdicts = $this->explain($items, array_map(fn (Rule $r): Candidate => new Candidate($r), $rules))
            ->getVerdicts();

        self::assertSame(
            [Status::REJECTED, Status::APPLIED, Status::APPLIED],
            array_map(fn (RuleVerdict $v): string => $v->getStatus(), $verdicts)
        );
        self::assertSame(
            [null, null, FreeShipping::MODE_ITEMS],
            array_map(fn (RuleVerdict $v): ?string => $v->getFreeShippingMode(), $verdicts)
        );
    }

    public function testDynamicBundleChildHasOwnFreeShippingStopState(): void
    {
        $dynamic = $this->item(20, 'BNDL', ['product' => $this->product('BNDL', 0)]);
        $dynamicChild = $this->item(21, 'A', [], [], $dynamic);
        $fixed = $this->item(30, 'FIX', ['product' => $this->product('FIX', 1)]);
        $fixedChild = $this->item(31, 'B', [], [], $fixed);
        $stopper = $this->rule(1, ['stop_rules_processing' => 1]);
        $this->actions = [1 => [21 => false], 2 => [31 => false], 3 => [21 => false]];
        $rules = [$stopper];
        foreach ([2, 3] as $id) {
            $rules[] = $this->rule($id, ['simple_free_shipping' => 1]);
        }

        $verdicts = $this->explain(
            [$dynamic, $dynamicChild, $fixed, $fixedChild],
            array_map(fn (Rule $r): Candidate => new Candidate($r), $rules)
        )->getVerdicts();

        self::assertSame(
            [null, FreeShipping::MODE_ITEMS, null],
            array_map(fn (RuleVerdict $v): ?string => $v->getFreeShippingMode(), $verdicts)
        );
    }

    public function testFreeShippingConditionsSeeSubtotalAfterAllDiscounts(): void
    {
        $this->conditions = [
            1 => fn (float $subtotal): bool => $subtotal <= 90,
            2 => fn (float $subtotal): bool => $subtotal >= 90,
        ];
        $items = [$this->item(11, 'A', ['applied_rule_ids' => '2,3'], [3 => 20.0])];
        $quote = $this->quote($items, false, [], ['base_subtotal' => 100.0]);
        $rules = [
            $this->rule(1, ['simple_free_shipping' => 2]),
            $this->rule(2, ['simple_free_shipping' => 1]),
            $this->rule(3, []),
        ];

        $verdicts = $this->explainer($this->walker())
            ->explain($quote, array_map(fn (Rule $r): Candidate => new Candidate($r), $rules))
            ->getVerdicts();

        self::assertSame([Status::REJECTED, Stage::CONDITIONS], [$verdicts[0]->getStatus(), $verdicts[0]->getStage()]);
        self::assertSame(Status::APPLIED, $verdicts[1]->getStatus());
        self::assertSame(
            [FreeShipping::MODE_ADDRESS, null, null],
            array_map(fn (RuleVerdict $v): ?string => $v->getFreeShippingMode(), $verdicts)
        );
    }

    public function testAddressModeMatchStopsProcessingLaterParentsButNotChildren(): void
    {
        $dynamic = $this->item(20, 'BNDL', ['product' => $this->product('BNDL', 0)]);
        $child = $this->item(21, 'C', [], [], $dynamic);
        $items = [$this->item(11, 'A'), $this->item(12, 'B'), $dynamic, $child];
        $rules = [
            $this->rule(1, ['simple_free_shipping' => 2]),
            $this->rule(2, ['simple_free_shipping' => 1]),
            $this->rule(3, ['simple_free_shipping' => 1]),
        ];
        $this->actions = [
            1 => [12 => false, 20 => false, 21 => false],
            2 => [11 => false, 21 => false],
            3 => [11 => false, 12 => false, 20 => false],
        ];

        $verdicts = $this->explain($items, array_map(fn (Rule $r): Candidate => new Candidate($r), $rules))
            ->getVerdicts();

        self::assertSame(
            [FreeShipping::MODE_ADDRESS, null, FreeShipping::MODE_ITEMS],
            array_map(fn (RuleVerdict $v): ?string => $v->getFreeShippingMode(), $verdicts)
        );
    }

    public function testVirtualQuoteUsesBillingAddressAndEmptyQuoteIsRejected(): void
    {
        $quote = $this->quote([], true);

        $verdict = $this->explainer($this->walker())->explain($quote, [new Candidate($this->rule(1, []))])
            ->getVerdicts()[0];

        self::assertSame([Status::REJECTED, Stage::ACTIONS], [$verdict->getStatus(), $verdict->getStage()]);
        self::assertSame('The quote has no items.', $verdict->getReason());
    }

    /**
     * @param Item[] $items
     * @param Candidate[] $candidates
     * @return RulesExplanation
     */
    private function explain(array $items, array $candidates): RulesExplanation
    {
        return $this->explainer($this->walker())->explain($this->quote($items), $candidates);
    }

    /**
     * @param ConditionWalker $walker
     * @param CategoryCollectionFactory|null $categories
     * @return RuleExplainer
     */
    private function explainer(ConditionWalker $walker, ?CategoryCollectionFactory $categories = null): RuleExplainer
    {
        $validator = $this->createStub(Validator::class);
        $validator->method('canApplyDiscount')->willReturnCallback(
            fn (Item $item): bool => !in_array((int)$item->getId(), $this->invalidItems, true)
        );
        $locator = $this->createStub(ChildrenValidationLocator::class);
        $locator->method('isChildrenValidationRequired')->willReturnCallback(fn (): bool => $this->childrenValidation);

        return new RuleExplainer(
            $walker,
            $validator,
            $locator,
            new ItemLabel(),
            new CategoryLabels($categories ?? $this->createStub(CategoryCollectionFactory::class), new ValueLabels())
        );
    }

    /**
     * @param array<int, Warning> $warnings rule ID => walker warning
     * @return ConditionWalker
     */
    private function walker(array $warnings = []): ConditionWalker
    {
        $walker = $this->createStub(ConditionWalker::class);
        $walker->method('walkConditions')->willReturnCallback(
            function (Rule $rule, Address $address) use ($warnings): ConditionsResult {
                $subtotal = $address->getData('base_subtotal_with_discount');
                $this->seenSubtotals[(int)$rule->getId()] = $subtotal;
                $valid = $this->conditions[(int)$rule->getId()] ?? true;
                $valid = $valid instanceof \Closure ? $valid((float)$subtotal) : $valid;
                $leaf = new ConditionNode('1--1', 'Subtotal >= 100', $valid, true, null, '100', '93.60');

                return new ConditionsResult(
                    new ConditionNode('1', 'If ALL', $valid, true, 'all', null, null, [$leaf]),
                    $valid,
                    $warnings[(int)$rule->getId()] ?? null
                );
            }
        );
        $walker->method('walkActions')->willReturnCallback(
            function (Rule $rule, Item $item): ConditionNode {
                // A mock clone keeps its ID; core's clone has none.
                $id = isset($this->built[spl_object_id($item)]) ? (int)$item->getId() : 0;

                return new ConditionNode('1--' . $id, 'Item matches', $this->actions[(int)$rule->getId()][$id] ?? true);
            }
        );

        return $walker;
    }

    /**
     * @param Item[] $items
     * @param bool $virtual
     * @param RuleDiscountInterface[] $addressDiscounts
     * @param array<string, mixed> $addressData
     * @return Quote
     */
    private function quote(
        array $items,
        bool $virtual = false,
        array $addressDiscounts = [],
        array $addressData = []
    ): Quote {
        $address = $this->createPartialMock(Address::class, ['getAllItems', 'getExtensionAttributes']);
        $address->setData($addressData);
        $address->method('getAllItems')->willReturn($items);
        $address->method('getExtensionAttributes')->willReturn(new DataObject(['discounts' => $addressDiscounts]));
        $unused = $this->createPartialMock(Address::class, ['getAllItems']);
        $unused->method('getAllItems')->willThrowException(new \LogicException('wrong address'));

        $quote = $this->createPartialMock(Quote::class, ['isVirtual', 'getShippingAddress', 'getBillingAddress']);
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('getShippingAddress')->willReturn($virtual ? $unused : $address);
        $quote->method('getBillingAddress')->willReturn($virtual ? $address : $unused);
        $quote->setStoreId(1);

        return $quote;
    }

    /**
     * @param int $id
     * @param array<string, mixed> $data
     * @return Rule
     */
    private function rule(int $id, array $data): Rule
    {
        $rule = $this->createPartialMock(Rule::class, ['getId']);
        $rule->method('getId')->willReturn($id);
        $rule->setData($data + [
            'rule_id' => $id,
            'name' => 'Rule ' . $id,
            'sort_order' => 0,
            'simple_action' => Rule::BY_PERCENT_ACTION,
            'discount_step' => 0,
        ]);

        return $rule;
    }

    /**
     * @param int $id
     * @param string $sku
     * @param array<string, mixed> $data
     * @param array<int, float> $discounts rule ID => item discount
     * @param Item|null $parent
     * @return Item
     */
    private function item(int $id, string $sku, array $data = [], array $discounts = [], ?Item $parent = null): Item
    {
        $item = $this->createPartialMock(Item::class, ['getExtensionAttributes']);
        $ruleDiscounts = [];
        foreach ($discounts as $ruleId => $amount) {
            $ruleDiscounts[] = $this->discount($ruleId, $amount, DiscountAppliedToInterface::APPLIED_TO_ITEM);
        }
        $item->method('getExtensionAttributes')->willReturn(new DataObject(['discounts' => $ruleDiscounts]));
        $item->setData($data + ['id' => $id, 'item_id' => $id, 'sku' => $sku, 'qty' => 1]);
        $item->setParentItem($parent);
        $this->built[spl_object_id($item)] = true;

        return $item;
    }

    /**
     * @param string $sku
     * @param int|null $priceType 0 = children calculated (dynamic bundle)
     * @return DataObject
     */
    private function product(string $sku, ?int $priceType = null): DataObject
    {
        return new DataObject(['sku' => $sku, 'price_type' => $priceType]);
    }

    /**
     * @param int $ruleId
     * @param float $amount
     * @param string $appliedTo
     * @return RuleDiscountInterface
     */
    private function discount(int $ruleId, float $amount, string $appliedTo): RuleDiscountInterface
    {
        $data = self::getStubBuilder(DiscountData::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAmount', 'getBaseAmount', 'getAppliedTo'])
            ->getStub();
        $data->method('getAmount')->willReturn($amount);
        $data->method('getBaseAmount')->willReturn($amount);
        $data->method('getAppliedTo')->willReturn($appliedTo);
        $discount = $this->createStub(RuleDiscountInterface::class);
        $discount->method('getRuleID')->willReturn($ruleId);
        $discount->method('getDiscountData')->willReturn($data);

        return $discount;
    }

    /**
     * @param RuleVerdict $verdict
     * @return bool[]
     */
    private function applied(RuleVerdict $verdict): array
    {
        return array_map(fn (RuleItemOutcome $o): bool => $o->isApplied(), $verdict->getItems());
    }
}
