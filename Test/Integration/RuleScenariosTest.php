<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Rule\SalesRuleVersion;
use DmLab\PriceExplain\Model\Rule\RuleExplainer;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Cart rule verdicts on real quotes agree with core's applied_rule_ids.
 */
#[
    AppArea('frontend'),
    DbIsolation(false),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/catalog.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/cart_rules.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/tax_us_tx.php'),
]
class RuleScenariosTest extends ScenarioTestCase
{
    public function testSubtotalThresholdRejectedWithActualSubtotal(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-simple')]));

        $rule = $this->rule($report, 'PE Subtotal 200+');
        self::assertSame(Status::REJECTED, $rule->getStatus());
        self::assertSame(Stage::CONDITIONS, $rule->getStage());
        $leaves = array_column($rule->getConditions()->getFailingChecks(), 'leaf');
        self::assertCount(1, $leaves);
        self::assertEquals(200, $leaves[0]->getExpected());
        self::assertEquals(100, $leaves[0]->getActual());
        $this->assertMatchesCore($report);
    }

    public function testSubtotalThresholdApplied(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-simple', 2)]));

        self::assertSame(Status::APPLIED, $this->rule($report, 'PE Subtotal 200+')->getStatus());
        $this->assertMatchesCore($report);
    }

    public function testCouponPresent(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-simple')], 'PE_ONCE'));

        self::assertSame('PE_ONCE', $report->getCouponCode());
        self::assertSame(Status::APPLIED, $this->rule($report, 'PE Coupon once')->getStatus());
        $this->assertMatchesCore($report);
    }

    public function testCouponAbsent(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-simple')]));

        $rule = $this->rule($report, 'PE Coupon once');
        self::assertSame(Status::REJECTED, $rule->getStatus());
        self::assertSame(Stage::COUPON, $rule->getStage());
        $this->assertMatchesCore($report);
    }

    public function testCouponUsedUp(): void
    {
        $quote = $this->createQuote([self::line('pe-simple')], 'PE_ONCE');
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $table = $connection->getTableName('salesrule_coupon');
        $connection->update($table, ['usage_limit' => 1, 'times_used' => 1], ['code = ?' => 'PE_ONCE']);
        try {
            $report = $this->explain($quote);
        } finally {
            $connection->update($table, ['usage_limit' => 0, 'times_used' => 0], ['code = ?' => 'PE_ONCE']);
        }

        $rule = $this->rule($report, 'PE Coupon once');
        self::assertSame(Status::REJECTED, $rule->getStatus());
        self::assertSame(Stage::COUPON_USAGE, $rule->getStage());
        self::assertStringContainsString('1 of 1', (string)$rule->getReason());
        self::assertNotContains($rule->getRuleId(), $this->appliedRuleIds($report));
        // The saved quote still has the coupon applied; compare with the recollection instead.
        self::assertNotContains(RuleExplainer::WARNING_CODE, $this->warningCodes($report));
    }

    public function testStopProcessingBlocksSubsequentRule(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-simple')]));

        $stopA = $this->rule($report, 'PE Stop A');
        self::assertSame(Status::APPLIED, $stopA->getStatus());
        $blocked = $this->outcome($this->rule($report, 'PE Stop B'), 'pe-simple');
        self::assertFalse($blocked->isApplied());
        self::assertSame(Stage::STOPPED_BY, $blocked->getStage());
        self::assertSame($stopA->getRuleId(), $blocked->getStoppedBy());
        $this->assertMatchesCore($report);
    }

    public function testDiscountStepBelowQty(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-tier', 2)]));

        $outcome = $this->outcome($this->rule($report, 'PE Step 3'), 'pe-tier');
        // Before 2.4.8 core applies the rule with zero discount instead of skipping the item.
        $skips = Bootstrap::getObjectManager()->get(SalesRuleVersion::class)->skipsBelowDiscountStep();
        self::assertSame(!$skips, $outcome->isApplied());
        self::assertSame(Stage::DISCOUNT_STEP, $outcome->getStage());
        if (!$skips) {
            self::assertSame(0.0, $outcome->getAmount());
        }
        $this->assertMatchesCore($report);
    }

    public function testActionsMismatchOnOneItem(): void
    {
        // Core checks the discount step before the actions, so both items reach the step.
        $report = $this->explain($this->createQuote([self::line('pe-tier', 3), self::line('pe-simple', 3)]));

        $rule = $this->rule($report, 'PE Step 3');
        self::assertSame(Status::APPLIED, $rule->getStatus());
        self::assertTrue($this->outcome($rule, 'pe-tier')->isApplied());
        $mismatch = $this->outcome($rule, 'pe-simple');
        self::assertFalse($mismatch->isApplied());
        self::assertSame(Stage::ACTIONS, $mismatch->getStage());
        self::assertFalse($mismatch->getActions()->getResult());
        $this->assertMatchesCore($report);
    }

    public function testFoundFoldsConfigurableIntoOneRow(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-conf', 1, 'Blue'), self::line('pe-simple')]));

        $rule = $this->rule($report, 'PE Found blue');
        $found = $rule->getConditions()->getChildren()[0];
        $rows = [];
        foreach ($found->getChildren() as $row) {
            $rows[$row->getDescription()] = count((array)$row->getItemIds());
        }
        ksort($rows);
        self::assertSame(['pe-conf → pe-conf-blue' => 2, 'pe-simple' => 1], $rows);
        // Core checks the parent against its child too, so both members agree and fold into one leaf.
        $leaf = $found->getChildren()[0]->getChildren()[0];
        self::assertSame('pe-conf-blue', $leaf->getActual());
        self::assertNull($leaf->getMemberActuals());
        self::assertSame(Status::APPLIED, $rule->getStatus());
        $this->assertMatchesCore($report);
    }

    /**
     * Rule IDs applied to any item by the explainer.
     *
     * @param Report $report
     * @return int[]
     */
    private function appliedRuleIds(Report $report): array
    {
        $ids = [];
        foreach ($report->getRules() as $rule) {
            if ($rule->getStatus() === Status::APPLIED) {
                $ids[] = $rule->getRuleId();
            }
        }

        return $ids;
    }
}
