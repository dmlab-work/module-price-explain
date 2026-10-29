<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Model\Explainer;
use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end explanation of a real quote; the recollection must leave no trace in the database.
 */
#[
    AppArea('frontend'),
    DbIsolation(false),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/catalog.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/cart_rules.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/tax_us_tx.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/quote.php'),
]
class ExplainerSmokeTest extends TestCase
{
    private const TABLES = [
        'quote',
        'quote_item',
        'quote_item_option',
        'quote_address',
        'quote_address_item',
        'quote_shipping_rate',
        'salesrule_coupon_usage',
    ];

    public function testExplainsQuoteWithoutWritingToTheDatabase(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $quote = $objectManager->create(Quote::class);
        $objectManager->get(QuoteResource::class)->load($quote, 'pe_quote', 'reserved_order_id');
        $before = $this->checksums();

        $report = $objectManager->create(ExplainerInterface::class)->explainQuote((int)$quote->getId());

        self::assertSame($before, $this->checksums());
        self::assertSame((int)$quote->getId(), $report->getQuoteId());
        self::assertSame(
            ['pe-conf → pe-conf-red', 'pe-simple'],
            $this->sorted(array_map(static fn (ItemPrice $i): string => $i->getLabel(), $report->getItems()))
        );
        self::assertSame(Status::APPLIED, $this->rule($report->getRules(), 'PE Stop A')->getStatus());
        self::assertSame(
            ['pe-conf → pe-conf-red' => true, 'pe-simple' => false],
            $this->appliedByItem($this->rule($report->getRules(), 'PE Stop B'))
        );
        self::assertNotNull($report->getTax());

        $saved = $objectManager->create(Quote::class);
        $objectManager->get(QuoteResource::class)->load($saved, (int)$quote->getId());
        $totals = $report->toArray()['totals'];
        self::assertSame(number_format((float)$saved->getGrandTotal(), 2, '.', ''), $totals['current']['grand_total']);
        self::assertNull($totals['ordered']);
        $grandTotal = array_values(array_filter(
            $report->getCollectors(),
            static fn (CollectorStep $step): bool => $step->getCode() === 'grand_total' && $step->hasChanges()
        ));
        self::assertNotEmpty($grandTotal);
        $delta = array_sum(array_map(
            static fn (CollectorStep $step): float => $step->getDelta()['grand_total'] ?? 0.0,
            $grandTotal
        ));
        self::assertEqualsWithDelta((float)$saved->getGrandTotal(), $delta, 0.0001);

        $codes = array_map(static fn (Warning $w): string => $w->getCode(), $report->getWarnings());
        self::assertNotContains('rule_mismatch', $codes);
        self::assertNotContains(Explainer::WARNING_AREA, $codes);
        self::assertNotFalse(json_encode($report->toArray()));
    }

    /**
     * Row count + CHECKSUM TABLE for every quote table the recollection touches.
     *
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $result = [];
        foreach (self::TABLES as $table) {
            $name = $resource->getTableName($table);
            $count = $connection->fetchOne("SELECT COUNT(*) FROM `{$name}`");
            $row = $connection->fetchRow("CHECKSUM TABLE `{$name}`");
            $result[$table] = $count . ':' . $row['Checksum'];
        }

        return $result;
    }

    /**
     * @param RuleVerdict[] $rules
     * @param string $name
     * @return RuleVerdict
     */
    private function rule(array $rules, string $name): RuleVerdict
    {
        foreach ($rules as $rule) {
            if ($rule->getName() === $name) {
                return $rule;
            }
        }
        self::fail(sprintf('Rule "%s" is not in the report.', $name));
    }

    /**
     * @param RuleVerdict $rule
     * @return array<string, bool>
     */
    private function appliedByItem(RuleVerdict $rule): array
    {
        $result = [];
        foreach ($rule->getItems() as $outcome) {
            $result[$outcome->getLabel()] = $outcome->isApplied();
        }
        ksort($result);

        return $result;
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
