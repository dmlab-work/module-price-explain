<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Price;

use DmLab\PriceExplain\Model\Price\ItemPriceExplainer;
use DmLab\PriceExplain\Model\Price\PricesExplanation;
use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Type\DefaultType;
use Magento\Catalog\Model\Product\Type\Price;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\CatalogRule\Model\ResourceModel\Rule\Collection as CatalogRuleCollection;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as CatalogRuleCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ItemPriceExplainerTest extends TestCase
{
    private const TODAY = '2026-10-28';

    /**
     * @var array<int, float|false> product ID => indexed catalog rule price
     */
    private array $rulePrices = [];

    /**
     * @var array<int, array[]> product ID => catalogrule_product rows
     */
    private array $ruleRows = [];

    /**
     * @var array<int, string> catalog rule ID => name
     */
    private array $ruleNames = [];

    /**
     * @var array[] event observers config
     */
    private array $observers = [];

    /**
     * @return array<string, array{array, float, string, float}>
     */
    public static function winnerProvider(): array
    {
        return [
            'base wins without discounts' => [[], 1, PriceLayer::CODE_BASE, 50.0],
            'special wins' => [['special_price' => 40], 1, PriceLayer::CODE_SPECIAL, 40.0],
            'tier wins' => [
                ['special_price' => 45, 'tiers' => [[2, 42.0]]],
                2,
                PriceLayer::CODE_TIER,
                42.0,
            ],
            'catalog rule wins' => [['special_price' => 45, 'rule_price' => 38.5], 1, 'catalog_rule', 38.5],
            'tie keeps the earlier layer' => [['special_price' => 40, 'rule_price' => 40.0], 1, 'special', 40.0],
        ];
    }

    /**
     * @param array $setup
     * @param float $qty
     * @param string $winner
     * @param float $final
     */
    #[DataProvider('winnerProvider')]
    public function testEachLayerWinning(array $setup, float $qty, string $winner, float $final): void
    {
        $product = $this->product(10, 50.0, $setup);
        if (isset($setup['rule_price'])) {
            $this->rulePrices[10] = $setup['rule_price'];
        }

        $result = $this->explain([$this->item(1, $product, ['qty' => $qty, 'price' => $final])]);
        $itemPrice = $result->getItems()[0];

        self::assertSame($winner, $itemPrice->getWinningLayer()->getCode());
        self::assertSame($final, $itemPrice->getFinal());
        self::assertNull($itemPrice->getUnexplainedDelta());
        self::assertSame([], $result->getWarnings());
        self::assertCount(1, array_filter($itemPrice->getLayers(), static fn (PriceLayer $l): bool => $l->isWon()));
    }

    public function testSpecialPriceOutsideDatesIsInactive(): void
    {
        $product = $this->product(10, 50.0, [
            'special_price' => 30,
            'special_from_date' => '2026-10-01',
            'special_to_date' => '2026-10-27',
        ]);

        $itemPrice = $this->explain([$this->item(1, $product, ['price' => 50.0])])->getItems()[0];
        $special = $this->layer($itemPrice, PriceLayer::CODE_SPECIAL);

        self::assertFalse($special->isActive());
        self::assertSame(30.0, $special->getValue());
        self::assertSame(['from' => '2026-10-01', 'to' => '2026-10-27'], $special->getDetails());
        self::assertSame(PriceLayer::CODE_BASE, $itemPrice->getWinningLayer()->getCode());
    }

    public function testSpecialPriceOnLastDayIsActive(): void
    {
        $product = $this->product(10, 50.0, ['special_price' => 30, 'special_to_date' => self::TODAY]);

        $itemPrice = $this->explain([$this->item(1, $product, ['price' => 30.0])])->getItems()[0];

        self::assertTrue($this->layer($itemPrice, PriceLayer::CODE_SPECIAL)->isActive());
        self::assertSame(30.0, $itemPrice->getFinal());
    }

    /**
     * @return array<string, array{float, bool}>
     */
    public static function tierBoundaryProvider(): array
    {
        return [
            'below the tier qty' => [4, false],
            'exactly the tier qty' => [5, true],
            'above the tier qty' => [6, true],
        ];
    }

    /**
     * @param float $qty
     * @param bool $active
     */
    #[DataProvider('tierBoundaryProvider')]
    public function testTierQtyBoundary(float $qty, bool $active): void
    {
        $product = $this->product(10, 50.0, ['tiers' => [[5, 45.0], [10, 40.0]]]);

        $itemPrice = $this->explain([$this->item(1, $product, ['qty' => $qty, 'price' => $active ? 45.0 : 50.0])])
            ->getItems()[0];
        $tier = $this->layer($itemPrice, PriceLayer::CODE_TIER);

        self::assertSame($active, $tier->isActive());
        self::assertSame($active ? 45.0 : null, $tier->getValue());
        self::assertSame($qty, $tier->getDetails()['qty']);
        self::assertSame(
            [
                ['qty' => 5.0, 'price' => 45.0, 'customer_group' => 32000],
                ['qty' => 10.0, 'price' => 40.0, 'customer_group' => 32000],
            ],
            $tier->getDetails()['tiers']
        );
        self::assertNull($itemPrice->getUnexplainedDelta());
    }

    public function testNoLayersBesideBaseAreInactive(): void
    {
        $itemPrice = $this->explain([$this->item(1, $this->product(10, 50.0), ['price' => 50.0])])->getItems()[0];

        self::assertSame(
            ['base', 'special', 'tier', 'catalog_rule'],
            array_map(static fn (PriceLayer $l): string => $l->getCode(), $itemPrice->getLayers())
        );
        foreach (['special', 'tier', 'catalog_rule'] as $code) {
            self::assertFalse($this->layer($itemPrice, $code)->isActive());
            self::assertNull($this->layer($itemPrice, $code)->getValue());
        }
    }

    public function testCatalogRuleNamesInSortOrderUpToStopRule(): void
    {
        $this->rulePrices[10] = 35.0;
        $this->ruleRows[10] = [
            ['rule_id' => 3, 'sort_order' => 2, 'action_stop' => 0],
            ['rule_id' => 1, 'sort_order' => 0, 'action_stop' => 0],
            ['rule_id' => 2, 'sort_order' => 1, 'action_stop' => 1],
        ];
        $this->ruleNames = [1 => 'Spring 10%', 2 => 'VIP 5%'];

        $itemPrice = $this->explain([$this->item(1, $this->product(10, 50.0), ['price' => 35.0])])->getItems()[0];
        $layer = $this->layer($itemPrice, PriceLayer::CODE_CATALOG_RULE);

        self::assertTrue($layer->isWon());
        self::assertSame(['Spring 10%', 'VIP 5%'], $layer->getRules());
    }

    public function testConfigurableIsPricedByChildWithParentOptions(): void
    {
        $child = $this->product(21, 60.0, ['special_price' => 55]);
        $child->setData('sku', 'MH01-M-Red');
        $parent = $this->product(20, 0.0, [
            'options' => ['5' => 4.5, '6' => 1.5],
            'simple_product' => $child,
            'sku' => 'MH01',
        ]);
        $item = $this->item(1, $parent, ['price' => 61.0, 'sku' => 'MH01-M-Red']);
        $this->item(2, $child, ['sku' => 'MH01-M-Red'])->setParentItem($item);

        $itemPrice = $this->explain([$item])->getItems()[0];

        self::assertSame('MH01 → MH01-M-Red', $itemPrice->getLabel());
        self::assertSame(21, $itemPrice->getProductId());
        self::assertSame(60.0, $this->layer($itemPrice, PriceLayer::CODE_BASE)->getValue());
        self::assertSame(PriceLayer::CODE_SPECIAL, $itemPrice->getWinningLayer()->getCode());
        self::assertSame(6.0, $this->layer($itemPrice, PriceLayer::CODE_OPTIONS)->getValue());
        self::assertSame(61.0, $itemPrice->getFinal());
        self::assertNull($itemPrice->getUnexplainedDelta());
    }

    public function testCustomPriceIsReportedAsOverride(): void
    {
        $item = $this->item(1, $this->product(10, 50.0), ['price' => 50.0, 'custom_price' => 20.0]);
        $item->method('getBaseCalculationPrice')->willReturn(10.0);

        $result = $this->explain([$item]);

        self::assertSame(10.0, $result->getItems()[0]->getCustomPrice());
        self::assertSame(50.0, $result->getItems()[0]->getItemPrice());
        self::assertSame([], $result->getWarnings());
    }

    public function testTaxInclusivePriceIsComparedBeforeTax(): void
    {
        $item = $this->item(1, $this->product(10, 120.0), ['price' => 100.0, 'base_tax_calculation_price' => 120.0]);

        $result = $this->explain([$item]);

        self::assertSame(120.0, $result->getItems()[0]->getItemPrice());
        self::assertNull($result->getItems()[0]->getUnexplainedDelta());
        self::assertSame([], $result->getWarnings());
    }

    public function testCustomPriceHasNoUnexplainedDelta(): void
    {
        $item = $this->item(1, $this->product(10, 50.0), ['price' => 20.0, 'custom_price' => 20.0]);
        $item->method('getBaseCalculationPrice')->willReturn(20.0);

        $result = $this->explain([$item]);

        self::assertNull($result->getItems()[0]->getUnexplainedDelta());
        self::assertSame([], $result->getWarnings());
    }

    public function testUnexplainedDeltaListsThirdPartyObservers(): void
    {
        $this->observers = [
            'catalogrule' => ['instance' => 'Magento\CatalogRule\Observer\ProcessFrontFinalPriceObserver'],
            'vendor' => ['instance' => '\Vendor\Pricing\Observer\Surcharge'],
            'disabled' => ['instance' => 'Vendor\Pricing\Observer\Off', 'disabled' => true],
        ];

        $result = $this->explain([$this->item(1, $this->product(10, 50.0), ['price' => 47.5, 'sku' => 'S1'])]);
        $itemPrice = $result->getItems()[0];

        self::assertSame(-2.5, $itemPrice->getUnexplainedDelta());
        self::assertSame(['Vendor\Pricing\Observer\Surcharge'], $itemPrice->getSuspects());
        self::assertCount(1, $result->getWarnings());
        $warning = $result->getWarnings()[0];
        self::assertSame(ItemPriceExplainer::WARNING_CODE, $warning->getCode());
        self::assertSame(
            ['item_id' => 1, 'delta' => -2.5, 'suspects' => ['Vendor\Pricing\Observer\Surcharge']],
            $warning->getContext()
        );
        self::assertStringContainsString('S1', $warning->getMessage());
    }

    public function testChildrenCalculatedItemIsNotRecomputed(): void
    {
        $item = $this->item(1, $this->product(10, 0.0), ['price' => 99.0]);
        $item->method('isChildrenCalculated')->willReturn(true);

        $result = $this->explain([$item]);

        self::assertSame([], $result->getItems()[0]->getLayers());
        self::assertNull($result->getItems()[0]->getFinal());
        self::assertSame([], $result->getWarnings());
    }

    /**
     * @return array<string, array{array}>
     */
    public static function ownPriceModelProvider(): array
    {
        return [
            'fixed bundle' => [['type_id' => 'bundle']],
            'downloadable with separate links' => [['type_id' => 'downloadable', 'links_purchased_separately' => 1]],
        ];
    }

    #[DataProvider('ownPriceModelProvider')]
    public function testProductWithOwnPriceModelIsNotRecomputed(array $setup): void
    {
        $result = $this->explain([$this->item(1, $this->product(10, 80.0, $setup), ['price' => 95.0])]);

        self::assertSame([], $result->getItems()[0]->getLayers());
        self::assertNull($result->getItems()[0]->getFinal());
        self::assertSame([], $result->getWarnings());
    }

    public function testDownloadableWithoutSeparateLinksIsRecomputed(): void
    {
        $product = $this->product(10, 20.0, ['type_id' => 'downloadable', 'links_purchased_separately' => 0]);

        $itemPrice = $this->explain([$this->item(1, $product, ['price' => 20.0])])->getItems()[0];

        self::assertSame(20.0, $itemPrice->getFinal());
    }

    public function testFinalPriceNeverNegative(): void
    {
        $product = $this->product(10, 5.0, ['options' => ['7' => -8.0]]);

        $itemPrice = $this->explain([$this->item(1, $product, ['price' => 0.0])])->getItems()[0];

        self::assertSame(0.0, $itemPrice->getFinal());
        self::assertNull($itemPrice->getUnexplainedDelta());
    }

    /**
     * @param Item[] $items
     * @return PricesExplanation
     */
    private function explain(array $items): PricesExplanation
    {
        $resource = $this->createStub(CatalogRuleResource::class);
        $resource->method('getRulePrice')->willReturnCallback(
            fn ($date, $wId, $gId, $pId) => $wId === 1 && $gId === 2 ? ($this->rulePrices[$pId] ?? false) : false
        );
        $resource->method('getRulesFromProduct')->willReturnCallback(
            fn ($date, $wId, $gId, $pId): array => $this->ruleRows[$pId] ?? []
        );

        $collection = $this->createStub(CatalogRuleCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturnCallback(fn (): array => array_map(
            static fn (int $id, string $name): DataObject => new DataObject(['id' => $id, 'name' => $name]),
            array_keys($this->ruleNames),
            $this->ruleNames
        ));
        $collectionFactory = $this->createStub(CatalogRuleCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('scopeDate')->willReturn(new \DateTime(self::TODAY));
        $timezone->method('scopeTimeStamp')->willReturn(strtotime(self::TODAY));

        $eventConfig = $this->createStub(EventConfig::class);
        $eventConfig->method('getObservers')->willReturnCallback(fn (): array => $this->observers);

        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $quote = $this->createPartialMock(Quote::class, ['getAllVisibleItems', 'getStore']);
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getStore')->willReturn($store);
        $quote->setData(['store_id' => 1, 'customer_group_id' => 2]);

        $explainer = new ItemPriceExplainer($resource, $collectionFactory, $timezone, $eventConfig, new ItemLabel());

        return $explainer->explain($quote);
    }

    /**
     * @param int $id
     * @param Product $product
     * @param array $data
     * @return Item
     */
    private function item(int $id, Product $product, array $data = []): Item
    {
        $item = $this->createPartialMock(
            Item::class,
            ['getProduct', 'getOptionByCode', 'isChildrenCalculated', 'getBaseCalculationPrice']
        );
        $item->method('getProduct')->willReturn($product);
        $child = $product->getData('simple_product');
        $item->method('getOptionByCode')->willReturnCallback(
            static fn (string $code) => $code === 'simple_product' && $child
                ? new DataObject(['product' => $child])
                : null
        );
        $item->setData($data + ['id' => $id, 'sku' => 'SKU' . $id, 'name' => 'Item ' . $id, 'qty' => 1]);

        return $item;
    }

    /**
     * Product stub; setup keys: special_price/_from_date/_to_date, tiers [[qty, price]], options [id => price],
     * simple_product, type_id, links_purchased_separately.
     *
     * @param int $id
     * @param float $price
     * @param array $setup
     * @return Product
     */
    private function product(int $id, float $price, array $setup = []): Product
    {
        $tiers = array_map(
            static fn (array $t): array => ['price_qty' => $t[0], 'website_price' => $t[1], 'cust_group' => 32000],
            $setup['tiers'] ?? []
        );

        $priceModel = $this->createStub(Price::class);
        $priceModel->method('calculateSpecialPrice')->willReturnCallback(
            static function ($final, $special, $from, $to) {
                $inInterval = (!$from || $from <= self::TODAY) && (!$to || $to >= self::TODAY);

                return $inInterval ? min($final, (float)$special) : $final;
            }
        );

        $product = $this->createPartialMock(
            Product::class,
            ['getId', 'getPrice', 'getPriceModel', 'getTierPrice', 'getCustomOption', 'getOptionById']
        );
        $product->method('getId')->willReturn($id);
        $product->method('getPrice')->willReturn($price);
        $product->method('getPriceModel')->willReturn($priceModel);
        $product->method('getTierPrice')->willReturnCallback(static function ($qty = null) use ($tiers, $price) {
            if ($qty === null) {
                return $tiers;
            }
            $best = $price;
            foreach ($tiers as $tier) {
                if ($qty >= $tier['price_qty']) {
                    $best = min($best, $tier['website_price']);
                }
            }

            return $best;
        });

        $options = $setup['options'] ?? [];
        $product->method('getCustomOption')->willReturnCallback(static function (string $code) use ($options) {
            if ($code === 'option_ids') {
                return $options ? new DataObject(['value' => implode(',', array_keys($options))]) : null;
            }

            return new DataObject(['value' => 'selected']);
        });
        $product->method('getOptionById')->willReturnCallback(
            fn ($optionId) => isset($options[$optionId]) ? $this->option((int)$optionId, $options[$optionId]) : null
        );
        $product->setData(array_intersect_key(
            $setup,
            array_flip([
                'special_price',
                'special_from_date',
                'special_to_date',
                'simple_product',
                'type_id',
                'links_purchased_separately',
                'sku',
            ])
        ));

        return $product;
    }

    /**
     * @param int $id
     * @param float $price
     * @return Option
     */
    private function option(int $id, float $price): Option
    {
        $group = $this->createPartialMock(DefaultType::class, ['getOptionPrice']);
        $group->method('getOptionPrice')->willReturn($price);

        $option = $this->createPartialMock(Option::class, ['getId', 'getType', 'groupFactory']);
        $option->method('getId')->willReturn($id);
        $option->method('getType')->willReturn('field');
        $option->method('groupFactory')->willReturn($group);

        return $option;
    }

    /**
     * @param ItemPrice $itemPrice
     * @param string $code
     * @return PriceLayer
     */
    private function layer(ItemPrice $itemPrice, string $code): PriceLayer
    {
        foreach ($itemPrice->getLayers() as $layer) {
            if ($layer->getCode() === $code) {
                return $layer;
            }
        }
        self::fail('Layer ' . $code . ' not found');
    }
}
