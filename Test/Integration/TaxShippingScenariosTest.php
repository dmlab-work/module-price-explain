<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Report\TaxLine;
use DmLab\PriceExplain\Model\Shipping\FreeShippingExplainer;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;

/**
 * Tax lines and free-shipping verdicts on real quotes shipped by Flat Rate.
 */
#[
    AppArea('frontend'),
    DbIsolation(false),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/catalog.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/cart_rules.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/tax_us_tx.php'),
]
class TaxShippingScenariosTest extends ScenarioTestCase
{
    public function testTaxedItemCarriesItsRate(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-untaxed'), self::line('pe-simple')]));

        $line = $this->taxLine($report, 'pe-simple');
        self::assertGreaterThan(0, $line->getAmount());
        self::assertNull($line->getReason());
        self::assertSame(['PE US-TX'], array_column($line->getRates(), 'code'));
        self::assertEquals(8.25, $line->getRates()[0]['percent']);
    }

    public function testUntaxedClassHasReason(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-untaxed'), self::line('pe-simple')]));

        $line = $this->taxLine($report, 'pe-untaxed');
        self::assertEquals(0.0, $line->getAmount());
        self::assertSame('The product tax class is "None".', $line->getReason());
    }

    public function testShipmentFreeShippingIsNotHonouredByFlatRate(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-simple')], 'PE_FS_ADDR'));

        $section = $report->getFreeShipping();
        self::assertNotNull($section);
        self::assertSame(FreeShipping::NOT_HONOURED, $section->getVerdict());
        self::assertTrue($section->hasAddressFlag());
        self::assertSame('flatrate_flatrate', $section->getMethod());
        self::assertGreaterThan(0, $section->getRatePrice());
        self::assertSame(
            FreeShipping::NOT_HONOURED,
            $this->rule($report, 'PE Free shipping address')->getFreeShippingOutcome()
        );
        self::assertContains(FreeShippingExplainer::WARNING_CODE, $this->warningCodes($report));
        $this->assertMatchesCore($report);
    }

    public function testItemFreeShippingIsEffective(): void
    {
        $report = $this->explain($this->createQuote(
            [self::line('pe-simple'), self::line('pe-conf', 1, 'Red')],
            'PE_FS_ITEMS'
        ));

        $section = $report->getFreeShipping();
        self::assertNotNull($section);
        self::assertSame(FreeShipping::EFFECTIVE, $section->getVerdict());
        self::assertEquals(0.0, $section->getRatePrice());
        self::assertEquals($section->getPackageQty(), $section->getFreeBoxes());
        self::assertSame(
            FreeShipping::EFFECTIVE,
            $this->rule($report, 'PE Free shipping items')->getFreeShippingOutcome()
        );
        self::assertNotContains(FreeShippingExplainer::WARNING_CODE, $this->warningCodes($report));
        $this->assertMatchesCore($report);
    }

    /**
     * @param Report $report
     * @param string $label
     * @return TaxLine
     */
    private function taxLine(Report $report, string $label): TaxLine
    {
        foreach ($report->getTax()->getLines() as $line) {
            if ($line->getType() === TaxLine::TYPE_ITEM && $line->getLabel() === $label) {
                return $line;
            }
        }
        self::fail(sprintf('No tax line for "%s".', $label));
    }
}
