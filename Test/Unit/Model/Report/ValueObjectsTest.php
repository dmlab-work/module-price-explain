<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Report;

use DmLab\PriceExplain\Model\Report\FormatsAmounts;
use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use DmLab\PriceExplain\Model\Report\QuoteScope;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\TaxLine;
use DmLab\PriceExplain\Model\Report\Warning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueObjectsTest extends TestCase
{
    /**
     * @return array<string, array{float|null, int, string|null}>
     */
    public static function amountProvider(): array
    {
        return [
            'null' => [null, 2, null],
            'pads' => [5.0, 2, '5.00'],
            'rounds half up' => [1.005, 2, '1.01'],
            'negative' => [-9.355, 2, '-9.36'],
            'negative zero' => [-0.001, 2, '0.00'],
            'zero precision' => [12.5, 0, '13'],
            'no thousands separator' => [12345.6, 2, '12345.60'],
        ];
    }

    #[DataProvider('amountProvider')]
    public function testAmountFormat(?float $value, int $precision, ?string $expected): void
    {
        self::assertSame($expected, $this->formatter()->amount($value, $precision));
    }

    public function testPercentTrimsZeros(): void
    {
        $formatter = $this->formatter();

        self::assertSame('8.25', $formatter->percent(8.25));
        self::assertSame('10', $formatter->percent(10.0));
        self::assertSame('0', $formatter->percent(0.0));
        self::assertSame('7.125', $formatter->percent(7.125));
    }

    public function testQtyIsIntWhenWhole(): void
    {
        self::assertSame(2, $this->formatter()->qty(2.0));
        self::assertSame(1.5, $this->formatter()->qty(1.5));
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuleVerdict(1, 'R', 0, 'maybe');
    }

    public function testUnknownStageIsRejectedOnVerdict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuleVerdict(1, 'R', 0, Status::REJECTED, 'nope');
    }

    public function testUnknownStageIsRejectedOnItemOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuleItemOutcome(1, 'A', false, 'nope');
    }

    public function testEveryStageIsValid(): void
    {
        self::assertCount(13, Stage::ALL);
        foreach (Stage::ALL as $stage) {
            self::assertSame($stage, (new RuleItemOutcome(1, 'A', false, $stage))->getStage());
        }
        foreach (Status::ALL as $status) {
            self::assertSame($status, (new RuleVerdict(1, 'R', 0, $status))->getStatus());
        }
    }

    public function testRuleItemOutcomeLabelAndChildren(): void
    {
        $child = new RuleItemOutcome(21, 'A', true, null, null, -1.0, null, null, 'BNDL → A');
        $parent = new RuleItemOutcome(20, 'BNDL-A', true, null, null, -1.0, null, null, 'BNDL', [$child]);

        self::assertSame('SKU', (new RuleItemOutcome(5, 'SKU', true))->getLabel());
        self::assertSame('BNDL', $parent->getLabel());
        self::assertSame([$child], $parent->getChildren());
        $array = $parent->toArray();
        self::assertSame(['BNDL-A', 'BNDL'], [$array['sku'], $array['label']]);
        self::assertSame([$child->toArray()], $array['children']);
        self::assertSame('BNDL → A', $array['children'][0]['label']);
        self::assertSame([], $child->toArray()['children']);
    }

    public function testRuleVerdictGetters(): void
    {
        $conditions = new ConditionNode('1', 'all', true);
        $outcome = new RuleItemOutcome(5, 'SKU', true, null, null, -2.0, null, $conditions);
        $verdict = new RuleVerdict(
            3,
            'Name',
            4,
            Status::APPLIED,
            null,
            null,
            [$outcome],
            -2.0,
            $conditions,
            false,
            FreeShipping::MODE_ADDRESS,
            -5.0
        );

        self::assertSame(3, $verdict->getRuleId());
        self::assertSame('Name', $verdict->getName());
        self::assertSame(4, $verdict->getSortOrder());
        self::assertNull($verdict->getStage());
        self::assertNull($verdict->getReason());
        self::assertSame([$outcome], $verdict->getItems());
        self::assertSame(-2.0, $verdict->getAmount());
        self::assertSame($conditions, $verdict->getConditions());
        self::assertFalse($verdict->isStopRulesProcessing());
        self::assertTrue($verdict->isFreeShipping());
        self::assertSame(FreeShipping::MODE_ADDRESS, $verdict->toArray()['free_shipping']);
        self::assertNull($verdict->getFreeShippingOutcome());
        $annotated = $verdict->withFreeShippingOutcome(FreeShipping::STALE_RATE);
        self::assertSame(FreeShipping::STALE_RATE, $annotated->toArray()['free_shipping_outcome']);
        self::assertSame('Stale shipping rate', $annotated->toArray()['free_shipping_outcome_label']);
        self::assertSame(
            $verdict->toArray(),
            array_merge($annotated->toArray(), ['free_shipping_outcome' => null, 'free_shipping_outcome_label' => null])
        );
        self::assertSame(-5.0, $verdict->getShippingAmount());
        self::assertSame('-5.00', $verdict->toArray()['shipping_amount']);

        self::assertSame(5, $outcome->getItemId());
        self::assertSame('SKU', $outcome->getSku());
        self::assertTrue($outcome->isApplied());
        self::assertNull($outcome->getReason());
        self::assertSame(-2.0, $outcome->getAmount());
        self::assertNull($outcome->getStoppedBy());
        self::assertSame($conditions, $outcome->getActions());
    }

    public function testFailingLeavesSkipPassedAnyGroupAndFollowFalseGroups(): void
    {
        $passedAny = new ConditionNode('1--1', 'any', true, true, 'any', true, null, [
            new ConditionNode('1--1--1', 'x', false),
            new ConditionNode('1--1--2', 'y', true),
        ]);
        $blocking = new ConditionNode('1--2--1', 'country is US', true);
        $falseGroup = new ConditionNode('1--2', 'all false', false, true, 'all', false, null, [
            $blocking,
            new ConditionNode('1--2--2', 'qty > 5', false),
        ]);
        $subselect = new ConditionNode('1--3', 'qty total >= 5', false, true, 'all', '5', 3, [
            new ConditionNode('1--3@1', 'Item #1', false, true, 'all', true),
        ]);
        $root = new ConditionNode('1', 'root', false, true, 'all', true, null, [$passedAny, $falseGroup, $subselect]);

        self::assertSame([$blocking, $subselect], array_column($root->getFailingChecks(), 'leaf'));
        self::assertSame([], array_column($passedAny->getFailingChecks(), 'leaf'));
    }

    public function testConditionNodeFoldedItemFieldsSurviveCopies(): void
    {
        $members = [['label' => 'MP01', 'actual' => 'MP01', 'actual_label' => 'MP01', 'result' => false]];
        $leaf = (new ConditionNode('1--1--1', 'sku is X', true, true, null, 'X', 'MP01'))
            ->withMemberActuals(false, $members, ['MP01']);
        $check = new ConditionNode(
            '1--1@1',
            'MP01',
            false,
            true,
            'all',
            true,
            null,
            [$leaf],
            null,
            'MP01',
            null,
            null,
            null,
            null,
            [1, 2]
        );

        $copy = $check->map(
            static fn (ConditionNode $n): ConditionNode => $n->withLabels(['X'], $n->getActualLabels())
        );

        self::assertFalse($leaf->getResult());
        self::assertSame(['MP01'], $leaf->getActualLabels());
        self::assertSame([1, 2], $copy->getItemIds());
        self::assertSame($members, $copy->getChildren()[0]->getMemberActuals());
        self::assertSame(['X'], $copy->getChildren()[0]->getExpectedLabels());
        self::assertSame([1, 2], $copy->toArray()['item_ids']);
        self::assertNull((new ConditionNode('1', 'root', true))->toArray()['member_actuals']);
    }

    public function testConditionNodeFailingLeavesSkipsUnevaluatedAndTrue(): void
    {
        $failA = new ConditionNode('1--1', 'a', false, true, null, '1', '0');
        $pass = new ConditionNode('1--2', 'b', true);
        $unknown = new ConditionNode('1--3', 'c', false, false, null, null, null, [], 'no explanation available');
        $failB = new ConditionNode('1--4--1', 'd', false);
        $root = new ConditionNode('1', 'root', false, true, 'all', true, null, [
            $failA,
            $pass,
            $unknown,
            new ConditionNode('1--4', 'nested', false, true, 'any', true, null, [$failB]),
        ]);

        self::assertSame([$failA, $failB], array_column($root->getFailingChecks(), 'leaf'));
        self::assertSame(
            [],
            array_column($root->getFailingChecks(false), 'leaf'),
            'a failed node is what "false" wants'
        );
        self::assertSame('no explanation available', $root->toArray()['children'][2]['note']);
        self::assertFalse($root->toArray()['children'][2]['evaluated']);
        self::assertSame('1', $root->getPath());
        self::assertSame('root', $root->getDescription());
        self::assertFalse($root->getResult());
        self::assertTrue($root->isEvaluated());
        self::assertSame('all', $root->getAggregator());
        self::assertTrue($root->getExpected());
        self::assertNull($root->getActual());
        self::assertCount(4, $root->getChildren());
        self::assertNull($root->getNote());
    }

    public function testItemPriceWinningLayerAndGetters(): void
    {
        $base = new PriceLayer('base', 10.0);
        $tier = new PriceLayer('tier', 8.0, true, true, ['qty' => 5]);
        $item = new ItemPrice(1, 'A', 'Name', 5.0, 2, [$base, $tier], 8.0, 9.0, 9.0, 1.0, ['Vendor\\Observer'], 'P→A');

        self::assertSame($tier, $item->getWinningLayer());
        self::assertSame(1, $item->getItemId());
        self::assertSame('A', $item->getSku());
        self::assertSame('P→A', $item->getLabel());
        self::assertSame('P→A', $item->toArray()['label']);
        self::assertSame('Name', $item->getName());
        self::assertSame(5.0, $item->getQty());
        self::assertSame(2, $item->getProductId());
        self::assertSame([$base, $tier], $item->getLayers());
        self::assertSame(8.0, $item->getFinal());
        self::assertSame(9.0, $item->getItemPrice());
        self::assertSame(9.0, $item->getCustomPrice());
        self::assertSame(1.0, $item->getUnexplainedDelta());
        self::assertSame(['Vendor\\Observer'], $item->getSuspects());
        self::assertSame('1.00', $item->toArray()['unexplained_delta']);
        self::assertSame(['qty' => 5], $item->toArray()['layers'][1]['details']);

        self::assertSame('tier', $tier->getCode());
        self::assertSame(8.0, $tier->getValue());
        self::assertTrue($tier->isActive());
        self::assertSame(['qty' => 5], $tier->getDetails());
        self::assertSame([], $tier->getRules());

        self::assertNull((new ItemPrice(1, 'A', 'A', 1.0, 1, [$base], null, 10.0))->getWinningLayer());
        self::assertSame('A', (new ItemPrice(1, 'A', 'A', 1.0, 1, [$base], null, 10.0))->getLabel());
        self::assertNull((new PriceLayer('special', null, false))->toArray()['value']);
    }

    public function testCollectorStepGetters(): void
    {
        $step = new CollectorStep('vendor_fee', 'Vendor\\Fee\\Total', true, 'billing', ['fee' => 1.5]);

        self::assertSame('vendor_fee', $step->getCode());
        self::assertSame('Vendor\\Fee\\Total', $step->getClass());
        self::assertTrue($step->isThirdParty());
        self::assertSame('billing', $step->getAddressType());
        self::assertSame(['fee' => 1.5], $step->getDelta());
        self::assertSame([], $step->getBaseDelta());
        self::assertSame(['fee' => '1.50'], $step->toArray()['delta']);
        self::assertNull($step->getSortOrder());
        self::assertTrue($step->hasChanges());
    }

    public function testCollectorStepSortOrderAndChanges(): void
    {
        $unchanged = new CollectorStep('subtotal', 'Magento\\Quote\\Subtotal', false, 'billing', [], [], 100);
        $baseOnly = new CollectorStep('fee', 'Vendor\\Fee', true, 'shipping', [], ['fee' => 0.5]);

        self::assertSame(100, $unchanged->getSortOrder());
        self::assertSame(100, $unchanged->toArray()['sort_order']);
        self::assertFalse($unchanged->hasChanges());
        self::assertTrue($baseOnly->hasChanges());
    }

    public function testQuoteScopeGetters(): void
    {
        $customer = new QuoteScope('Default Store View', 'Main Website', 'General', 5, 'roni@example.com', 'Roni');
        $guest = new QuoteScope(null, null, null);

        self::assertSame('Default Store View', $customer->getStoreName());
        self::assertSame('Main Website', $customer->getWebsiteName());
        self::assertSame('General', $customer->getCustomerGroupName());
        self::assertSame(5, $customer->getCustomerId());
        self::assertFalse($customer->isGuest());
        self::assertSame('roni@example.com', $customer->getCustomerEmail());
        self::assertSame('Roni', $customer->getCustomerName());
        self::assertTrue($guest->isGuest());
        self::assertNull($guest->getCustomerEmail());
    }

    public function testTaxGetters(): void
    {
        $rates = [['code' => 'R', 'title' => 'T', 'percent' => 20.0, 'amount' => 2.0]];
        $line = new TaxLine(TaxLine::TYPE_ITEM, 3, 'SKU', 'Taxable Goods', 2.0, $rates);
        $tax = new TaxBreakdown('billing', 'UNIT_BASE_CALCULATION', null, ['country' => 'DE'], [], [$line], $rates);

        self::assertSame(TaxLine::TYPE_ITEM, $line->getType());
        self::assertSame(3, $line->getItemId());
        self::assertSame('SKU', $line->getLabel());
        self::assertSame('Taxable Goods', $line->getTaxClass());
        self::assertSame(2.0, $line->getAmount());
        self::assertSame($rates, $line->getRates());
        self::assertNull($line->getReason());

        self::assertSame('billing', $tax->getBasedOn());
        self::assertSame('UNIT_BASE_CALCULATION', $tax->getAlgorithm());
        self::assertNull($tax->getCustomerTaxClass());
        self::assertSame(['country' => 'DE'], $tax->getAddress());
        self::assertSame([], $tax->getFlags());
        self::assertSame([$line], $tax->getLines());
        self::assertSame($rates, $tax->getAppliedTaxes());
        self::assertSame('20', $tax->toArray()['applied_taxes'][0]['percent']);
    }

    public function testWarningGetters(): void
    {
        $warning = new Warning('index_mismatch', 'Index is stale', ['product_id' => 1]);

        self::assertSame('index_mismatch', $warning->getCode());
        self::assertSame('Index is stale', $warning->getMessage());
        self::assertSame(['product_id' => 1], $warning->getContext());
    }

    /**
     * Exposes the trait's private formatters.
     *
     * @return object
     */
    private function formatter(): object
    {
        return new class {
            use FormatsAmounts;

            public function amount(?float $value, int $precision): ?string
            {
                return $this->formatAmount($value, $precision);
            }

            public function percent(float $value): string
            {
                return $this->formatPercent($value);
            }

            public function qty(float $qty): int|float
            {
                return $this->formatQty($qty);
            }
        };
    }
}
