<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Report;

use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\OrderContext;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use DmLab\PriceExplain\Model\Report\QuoteScope;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\TaxLine;
use DmLab\PriceExplain\Model\Report\Totals;
use DmLab\PriceExplain\Model\Report\Warning;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    public function testEmptyReportHasStableShape(): void
    {
        $report = new Report(42, 1, 1, 0, null, 'USD');

        self::assertSame(
            [
                'quote' => [
                    'id' => 42,
                    'store' => 1,
                    'store_name' => null,
                    'website' => 1,
                    'website_name' => null,
                    'group' => 0,
                    'group_name' => null,
                    'customer' => null,
                    'coupon' => null,
                    'currency' => 'USD',
                ],
                'order' => null,
                'totals' => null,
                'items' => [],
                'collectors' => [],
                'rules' => [],
                'free_shipping' => null,
                'tax' => null,
                'warnings' => [],
            ],
            $report->toArray()
        );
    }

    public function testNestedReportSurvivesJsonRoundTrip(): void
    {
        $array = $this->nestedReport()->toArray();

        $json = json_encode($array, JSON_THROW_ON_ERROR);

        self::assertSame($array, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testNestedReportShape(): void
    {
        $array = $this->nestedReport()->toArray();

        self::assertSame('SPRING', $array['quote']['coupon']);

        $item = $array['items'][0];
        self::assertSame('MH01-M-Red', $item['sku']);
        self::assertSame(2, $item['qty']);
        self::assertSame(
            [
                'code' => 'base',
                'label' => 'Base price',
                'value' => '52.00',
                'active' => true,
                'won' => false,
                'state' => 'active',
                'state_label' => 'Active',
                'details' => [],
                'rules' => [],
            ],
            $item['layers'][0]
        );
        self::assertSame(['Spring 10%'], $item['layers'][1]['rules']);
        self::assertTrue($item['layers'][1]['won']);
        self::assertSame('46.80', $item['final']);
        self::assertSame('46.80', $item['item_price']);
        self::assertNull($item['custom_price']);
        self::assertNull($item['unexplained_delta']);

        self::assertSame(
            [
                'code' => 'discount',
                'class' => 'Magento\\SalesRule\\Model\\Quote\\Discount',
                'third_party' => false,
                'address' => 'shipping',
                'sort_order' => null,
                'delta' => ['discount' => '-9.36', 'grand' => '-9.36'],
                'base_delta' => ['discount' => '-9.36'],
            ],
            $array['collectors'][0]
        );

        $applied = $array['rules'][0];
        self::assertSame(Status::APPLIED, $applied['status']);
        self::assertSame('-9.36', $applied['amount']);
        self::assertSame('-9.36', $applied['items'][0]['amount']);
        self::assertTrue($applied['stop_rules_processing']);

        $rejected = $array['rules'][1];
        self::assertSame(Stage::CONDITIONS, $rejected['stage']);
        self::assertSame('all', $rejected['conditions']['aggregator']);
        self::assertSame('93.60', $rejected['conditions']['children'][0]['actual']);
        self::assertFalse($rejected['conditions']['children'][0]['result']);
        self::assertSame([], $rejected['items']);

        $stopped = $array['rules'][2]['items'][0];
        self::assertSame(Stage::STOPPED_BY, $stopped['stage']);
        self::assertSame(7, $stopped['stopped_by']);
        self::assertSame('any', $stopped['actions']['aggregator']);

        self::assertSame('shipping', $array['tax']['based_on']);
        self::assertSame(
            [['code' => 'US-CA', 'title' => 'CA', 'percent' => '8.25', 'amount' => '3.86']],
            $array['tax']['lines'][0]['rates']
        );
        self::assertSame('No rate matches', $array['tax']['lines'][1]['reason']);
        self::assertSame('10', $array['tax']['applied_taxes'][0]['percent']);
        self::assertSame(['price_includes_tax' => false], $array['tax']['flags']);

        self::assertSame(
            ['code' => 'rule_mismatch', 'message' => 'Core decided otherwise', 'context' => ['rule_id' => 9]],
            $array['warnings'][0]
        );
    }

    public function testQuoteSectionCarriesNamesAndCustomer(): void
    {
        $scope = new QuoteScope('Default Store View', 'Main Website', 'General', 5, 'roni@example.com', 'Roni');
        $quote = (new Report(1, 1, 1, 1, null, 'USD', 2, [], [], [], null, [], null, $scope))->toArray()['quote'];

        self::assertSame('Default Store View', $quote['store_name']);
        self::assertSame('Main Website', $quote['website_name']);
        self::assertSame('General', $quote['group_name']);
        self::assertSame(
            ['id' => 5, 'guest' => false, 'email' => 'roni@example.com', 'name' => 'Roni'],
            $quote['customer']
        );

        $guest = new QuoteScope('Default Store View', 'Main Website', 'NOT LOGGED IN');
        $quote = (new Report(1, 1, 1, 0, null, 'USD', 2, [], [], [], null, [], null, $guest))->toArray()['quote'];
        self::assertSame(['id' => null, 'guest' => true, 'email' => null, 'name' => null], $quote['customer']);
    }

    public function testAmountsUseReportPrecision(): void
    {
        $report = new Report(1, 1, 1, 0, null, 'JPY', 0, [
            new ItemPrice(1, 'A', 'A', 1.0, 1, [new PriceLayer('base', 1234.5)], 1234.5, 1234.5),
        ]);

        self::assertSame('1235', $report->toArray()['items'][0]['item_price']);
    }

    public function testWithWarningsAppendsWithoutMutating(): void
    {
        $freeShipping = new FreeShipping(FreeShipping::EFFECTIVE, 'Free');
        $scope = new QuoteScope('Default Store View', 'Main Website', 'General');
        $warnings = [new Warning('a', 'A')];
        $report = new Report(1, 1, 1, 0, null, 'USD', 2, [], [], [], null, $warnings, $freeShipping, $scope);

        $copy = $report->withWarnings([new Warning('b', 'B')]);
        self::assertSame($freeShipping, $copy->getFreeShipping());
        self::assertSame($scope, $copy->getScope());

        self::assertCount(1, $report->getWarnings());
        self::assertSame(['a', 'b'], array_map(static fn (Warning $w): string => $w->getCode(), $copy->getWarnings()));
        self::assertSame($report->getQuoteId(), $copy->getQuoteId());
    }

    public function testOrderReportCarriesOrderContext(): void
    {
        $report = new Report(3, 1, 1, 0, null, 'USD', 2, [], [], [], null, [new Warning('a', 'A')]);
        $order = new OrderContext('000000003', 7, '2026-10-04 00:33:26', 'pending');

        $copy = $report->withOrder($order)->withWarnings([new Warning('b', 'B')]);

        self::assertNull($report->getOrder());
        self::assertSame($order, $copy->getOrder());
        self::assertCount(2, $copy->getWarnings());
        self::assertSame(
            [
                'increment_id' => '000000003',
                'entity_id' => 7,
                'created_at' => '2026-10-04 00:33:26',
                'status' => 'pending',
            ],
            $copy->toArray()['order']
        );
        self::assertSame(3, $copy->toArray()['quote']['id']);
    }

    public function testTotalsUseReportPrecisionAndSurviveCopies(): void
    {
        $current = new Totals(['grand_total' => 10.125], []);
        $withOrdered = new Totals(['grand_total' => 10.125], [], null, ['grand_total' => 12.0]);
        $report = new Report(3, 1, 1, 0, null, 'USD', 3, [], [], [], null, [], null, null, null, $current);

        self::assertSame('10.125', $report->toArray()['totals']['current']['grand_total']);
        self::assertSame($current, $report->withWarnings([new Warning('a', 'A')])->getTotals());
        self::assertSame($current, $report->withOrder(new OrderContext('1', 1))->getTotals());
        self::assertSame($withOrdered, $report->withOrder(new OrderContext('1', 1), $withOrdered)->getTotals());
        self::assertSame(
            ['grand_total'],
            $report->withOrder(new OrderContext('1', 1), $withOrdered)->toArray()['totals']['differences']
        );
    }

    public function testGettersExposeConstructorValues(): void
    {
        $report = $this->nestedReport();

        self::assertSame(42, $report->getQuoteId());
        self::assertSame(1, $report->getStoreId());
        self::assertSame(1, $report->getWebsiteId());
        self::assertSame(1, $report->getCustomerGroupId());
        self::assertSame('SPRING', $report->getCouponCode());
        self::assertSame('USD', $report->getCurrency());
        self::assertSame(2, $report->getPrecision());
        self::assertCount(1, $report->getItems());
        self::assertCount(1, $report->getCollectors());
        self::assertCount(3, $report->getRules());
        self::assertSame('TOTAL_BASE_CALCULATION', $report->getTax()?->getAlgorithm());
        self::assertCount(1, $report->getWarnings());
    }

    private function nestedReport(): Report
    {
        $item = new ItemPrice(
            10,
            'MH01-M-Red',
            'Chaz Kangeroo Hoodie',
            2.0,
            1234,
            [
                new PriceLayer('base', 52.0),
                new PriceLayer('catalog_rule', 46.8, true, true, [], ['Spring 10%']),
                new PriceLayer('special', 50.0, false, false, ['from' => '2026-01-01', 'to' => '2026-02-01']),
            ],
            46.8,
            46.8
        );

        $conditions = new ConditionNode(
            '1',
            'If ALL of these conditions are TRUE',
            false,
            true,
            'all',
            true,
            null,
            [new ConditionNode('1--1', 'Subtotal equals or greater than 100', false, true, null, '100', '93.60')]
        );
        $actions = new ConditionNode('1', 'If ANY of these conditions are TRUE', true, true, 'any', true);

        $rules = [
            new RuleVerdict(
                7,
                'Spring',
                0,
                Status::APPLIED,
                null,
                null,
                [new RuleItemOutcome(10, 'MH01-M-Red', true, null, null, -9.36)],
                -9.36,
                null,
                true
            ),
            new RuleVerdict(
                9,
                'Free over 100',
                1,
                Status::REJECTED,
                Stage::CONDITIONS,
                'Conditions not met',
                [],
                null,
                $conditions
            ),
            new RuleVerdict(
                11,
                'Late',
                2,
                Status::REJECTED,
                Stage::STOPPED_BY,
                'Discarded by rule 7',
                [new RuleItemOutcome(10, 'MH01-M-Red', false, Stage::STOPPED_BY, 'Discarded', null, 7, $actions)]
            ),
        ];

        $tax = new TaxBreakdown(
            'shipping',
            'TOTAL_BASE_CALCULATION',
            'Retail Customer',
            ['country' => 'US', 'region' => 'CA', 'postcode' => '90001'],
            ['price_includes_tax' => false],
            [
                new TaxLine(
                    TaxLine::TYPE_ITEM,
                    10,
                    'MH01-M-Red',
                    'Taxable Goods',
                    3.861,
                    [['code' => 'US-CA', 'title' => 'CA', 'percent' => 8.25, 'amount' => 3.861]]
                ),
                new TaxLine(TaxLine::TYPE_SHIPPING, null, 'shipping', null, 0.0, [], 'No rate matches'),
            ],
            [['code' => 'X', 'title' => 'X', 'percent' => 10.0, 'amount' => 1.0]]
        );

        return new Report(
            42,
            1,
            1,
            1,
            'SPRING',
            'USD',
            2,
            [$item],
            [
                new CollectorStep(
                    'discount',
                    'Magento\\SalesRule\\Model\\Quote\\Discount',
                    false,
                    'shipping',
                    ['discount' => -9.36, 'grand' => -9.36],
                    ['discount' => -9.36]
                ),
            ],
            $rules,
            $tax,
            [new Warning('rule_mismatch', 'Core decided otherwise', ['rule_id' => 9])]
        );
    }
}
