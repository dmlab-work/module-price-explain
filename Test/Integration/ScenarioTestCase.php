<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Warning;
use DmLab\PriceExplain\Model\Rule\RuleExplainer;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductRepository;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Builds a guest quote per test (Austin, TX, Flat Rate) and compares the report with what core saved.
 */
abstract class ScenarioTestCase extends TestCase
{
    protected const RESERVED_ID = 'pe_scenario';

    /**
     * Guest quote with the given lines; a line is [sku, qty] or [sku, qty, pe_color label] for pe-conf.
     *
     * @param array[] $lines
     * @param string|null $coupon
     * @return Quote
     */
    protected function createQuote(array $lines, ?string $coupon = null): Quote
    {
        $objectManager = Bootstrap::getObjectManager();
        $products = $objectManager->get(ProductRepositoryInterface::class);
        $texas = $objectManager->get(RegionFactory::class)->create()->loadByCode('TX', 'US');

        $quote = $objectManager->get(QuoteFactory::class)->create();
        $quote->setStoreId(1)
            ->setIsActive(true)
            ->setIsMultiShipping(0)
            ->setReservedOrderId(self::RESERVED_ID)
            ->setCustomerIsGuest(true)
            ->setCustomerEmail('pe-guest@example.com');
        foreach ($lines as [$sku, $qty, $color]) {
            $request = ['qty' => $qty];
            if ($color !== null) {
                $attribute = $objectManager->get(ProductAttributeRepositoryInterface::class)->get('pe_color');
                $request['super_attribute'] = [
                    (int)$attribute->getAttributeId() => (int)$attribute->getSource()->getOptionId($color),
                ];
            }
            $quote->addProduct($products->get($sku, false, null, true), new DataObject($request));
        }

        $address = [
            'firstname' => 'Pat',
            'lastname' => 'Explain',
            'street' => '1 Main St',
            'city' => 'Austin',
            'region_id' => (int)$texas->getId(),
            'postcode' => '73301',
            'country_id' => 'US',
            'telephone' => '5125550100',
            'email' => 'pe-guest@example.com',
        ];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)
            ->setCollectShippingRates(true)
            ->setShippingMethod('flatrate_flatrate');
        if ($coupon !== null) {
            $quote->setCouponCode($coupon);
        }
        $quote->collectTotals();
        $objectManager->get(CartRepositoryInterface::class)->save($quote);

        return $quote;
    }

    /**
     * Line helper: [sku, qty, color].
     *
     * @param string $sku
     * @param float $qty
     * @param string|null $color
     * @return array
     */
    protected static function line(string $sku, float $qty = 1, ?string $color = null): array
    {
        return [$sku, $qty, $color];
    }

    /**
     * Explain a quote with a fresh explainer (one run per instance).
     *
     * @param Quote $quote
     * @return Report
     */
    protected function explain(Quote $quote): Report
    {
        return Bootstrap::getObjectManager()->create(ExplainerInterface::class)->explainQuote((int)$quote->getId());
    }

    /**
     * Applied rule IDs per item label must equal core's quote_item.applied_rule_ids; no reconciliation warning.
     *
     * @param Report $report
     * @return void
     */
    protected function assertMatchesCore(Report $report): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $core = $connection->fetchPairs(
            $connection->select()
                ->from($connection->getTableName('quote_item'), ['item_id', 'applied_rule_ids'])
                ->where('quote_id = ?', $report->getQuoteId())
                ->where('parent_item_id IS NULL')
        );
        $expected = [];
        $actual = [];
        foreach ($report->getItems() as $item) {
            $ids = array_map('intval', array_filter(explode(',', (string)($core[$item->getItemId()] ?? ''))));
            sort($ids);
            $expected[$item->getLabel()] = $ids;
            $actual[$item->getLabel()] = [];
        }
        foreach ($report->getRules() as $rule) {
            foreach ($rule->getItems() as $outcome) {
                if ($outcome->isApplied()) {
                    $actual[$outcome->getLabel()][] = $rule->getRuleId();
                }
            }
        }
        foreach ($actual as &$ids) {
            sort($ids);
        }
        unset($ids);
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
        self::assertNotContains(RuleExplainer::WARNING_CODE, $this->warningCodes($report));
    }

    /**
     * @param Report $report
     * @return string[]
     */
    protected function warningCodes(Report $report): array
    {
        return array_map(static fn (Warning $w): string => $w->getCode(), $report->getWarnings());
    }

    /**
     * @param Report $report
     * @param string $name
     * @return RuleVerdict
     */
    protected function rule(Report $report, string $name): RuleVerdict
    {
        foreach ($report->getRules() as $rule) {
            if ($rule->getName() === $name) {
                return $rule;
            }
        }
        self::fail(sprintf('Rule "%s" is not in the report.', $name));
    }

    /**
     * @param RuleVerdict $rule
     * @param string $label
     * @return RuleItemOutcome
     */
    protected function outcome(RuleVerdict $rule, string $label): RuleItemOutcome
    {
        foreach ($rule->getItems() as $outcome) {
            if ($outcome->getLabel() === $label) {
                return $outcome;
            }
        }
        self::fail(sprintf('Rule "%s" has no outcome for "%s".', $rule->getName(), $label));
    }

    /**
     * @param Report $report
     * @param string $label
     * @return ItemPrice
     */
    protected function item(Report $report, string $label): ItemPrice
    {
        foreach ($report->getItems() as $item) {
            if ($item->getLabel() === $label) {
                return $item;
            }
        }
        self::fail(sprintf('Item "%s" is not in the report.', $label));
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $quote = $objectManager->create(Quote::class);
        $objectManager->get(QuoteResource::class)->load($quote, self::RESERVED_ID, 'reserved_order_id');
        if ($quote->getId()) {
            $objectManager->get(QuoteResource::class)->delete($quote);
        }
        // Products loaded for the quote stay cached by SKU and break re-applying the catalog fixture.
        $objectManager->get(ProductRepository::class)->cleanCache();
        parent::tearDown();
    }
}
