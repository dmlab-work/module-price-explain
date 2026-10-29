<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Integration;

use DmLab\PriceExplain\Model\Price\IndexChecker;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Price layers of real products: which layer wins and when the price index disagrees.
 */
#[
    AppArea('frontend'),
    DbIsolation(false),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/catalog.php'),
    DataFixture('DmLab_PriceExplain::Test/Integration/_files/tax_us_tx.php'),
]
class PriceScenariosTest extends ScenarioTestCase
{
    public function testCatalogRuleWins(): void
    {
        $item = $this->item($this->explain($this->createQuote([self::line('pe-tier')])), 'pe-tier');

        self::assertSame(PriceLayer::CODE_CATALOG_RULE, $item->getWinningLayer()->getCode());
        self::assertEquals(40.0, $item->getFinal());
        self::assertSame(['PE Sale 20%'], $this->layer($item, PriceLayer::CODE_CATALOG_RULE)->getRules());
        self::assertNull($item->getUnexplainedDelta());
    }

    public function testTierPriceWins(): void
    {
        $item = $this->item($this->explain($this->createQuote([self::line('pe-tier', 2)])), 'pe-tier');

        self::assertSame(PriceLayer::CODE_TIER, $item->getWinningLayer()->getCode());
        self::assertEquals(35.0, $item->getFinal());
        self::assertTrue($this->layer($item, PriceLayer::CODE_CATALOG_RULE)->isActive());
        self::assertNull($item->getUnexplainedDelta());
    }

    public function testSpecialPriceOutsideDatesIsInactive(): void
    {
        $item = $this->item($this->explain($this->createQuote([self::line('pe-special')])), 'pe-special');

        $special = $this->layer($item, PriceLayer::CODE_SPECIAL);
        self::assertEquals(60.0, $special->getValue());
        self::assertFalse($special->isActive());
        self::assertNotNull($special->getDetails()['from']);
        self::assertSame(PriceLayer::CODE_BASE, $item->getWinningLayer()->getCode());
        self::assertEquals(80.0, $item->getFinal());
    }

    public function testConfigurableUsesChildPrice(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-conf', 1, 'Blue')]));
        $item = $this->item($report, 'pe-conf → pe-conf-blue');

        $child = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->get('pe-conf-blue');
        self::assertSame((int)$child->getId(), $item->getProductId());
        self::assertEquals(35.0, $this->layer($item, PriceLayer::CODE_BASE)->getValue());
        self::assertSame(PriceLayer::CODE_CATALOG_RULE, $item->getWinningLayer()->getCode());
        self::assertEquals(28.0, $item->getFinal());
        self::assertNull($item->getUnexplainedDelta());
    }

    public function testIndexMismatchAfterPriceEditWithoutReindex(): void
    {
        $quote = $this->createQuote([self::line('pe-simple')]);
        $restore = $this->setPriceWithoutReindex('pe-simple', 90.0);
        try {
            $report = $this->explain($quote);
        } finally {
            $restore();
        }

        self::assertEquals(90.0, $this->item($report, 'pe-simple')->getFinal());
        $mismatches = array_values(array_filter(
            $report->getWarnings(),
            static fn (Warning $w): bool => $w->getCode() === IndexChecker::WARNING_MISMATCH
        ));
        self::assertCount(1, $mismatches);
        self::assertSame('90.00', $mismatches[0]->getContext()['expected']);
        self::assertSame('100.00', $mismatches[0]->getContext()['final_price']);
    }

    public function testNoIndexWarningWhenIndexIsCurrent(): void
    {
        $report = $this->explain($this->createQuote([self::line('pe-simple'), self::line('pe-tier')]));

        self::assertNotContains(IndexChecker::WARNING_MISMATCH, $this->warningCodes($report));
        self::assertNotContains(IndexChecker::WARNING_MISSING, $this->warningCodes($report));
    }

    /**
     * @param ItemPrice $item
     * @param string $code
     * @return PriceLayer
     */
    private function layer(ItemPrice $item, string $code): PriceLayer
    {
        foreach ($item->getLayers() as $layer) {
            if ($layer->getCode() === $code) {
                return $layer;
            }
        }
        self::fail(sprintf('Item "%s" has no "%s" layer.', $item->getLabel(), $code));
    }

    /**
     * Write the default-scope price straight to EAV, bypassing save handlers and indexers.
     *
     * @param string $sku
     * @param float $price
     * @return callable restores the previous price
     */
    private function setPriceWithoutReindex(string $sku, float $price): callable
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $product = $objectManager->get(ProductRepositoryInterface::class)->get($sku);
        $linkField = $objectManager->get(MetadataPool::class)->getMetadata(ProductInterface::class)->getLinkField();
        $attributeId = (int)$objectManager->get(ProductAttributeRepositoryInterface::class)->get('price')
            ->getAttributeId();
        $table = $connection->getTableName('catalog_product_entity_decimal');
        $where = [
            $linkField . ' = ?' => (int)$product->getData($linkField),
            'attribute_id = ?' => $attributeId,
            'store_id = ?' => 0,
        ];
        $previous = (float)$product->getPrice();
        $connection->update($table, ['value' => $price], $where);

        return static fn () => $connection->update($table, ['value' => $previous], $where);
    }
}
