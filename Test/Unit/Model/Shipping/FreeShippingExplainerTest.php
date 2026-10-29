<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Shipping;

use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Shipping\FreeShippingExplainer;
use DmLab\PriceExplain\Model\Shipping\FreeShippingExplanation;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class FreeShippingExplainerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * @return array<string, array{0: string, 1: float, 2: bool, 3: bool[], 4: string, 5: string}>
     */
    public static function verdicts(): array
    {
        return [
            'flatrate, address mode only' => [
                'flatrate_flatrate', 10.0, true, [false, false], FreeShipping::NOT_HONOURED,
                'Flat Rate ignores shipment-level free shipping and charges 10.00; enable Free Shipping',
            ],
            'flatrate, all items free, still charged' => [
                'flatrate_flatrate', 10.0, true, [true, true], FreeShipping::STALE_RATE,
                'Flat Rate still charges 10.00 although free shipping is set',
            ],
            'flatrate, all items free' => [
                'flatrate_flatrate', 0.0, true, [true, true], FreeShipping::EFFECTIVE, 'Flat Rate charges 0.00.',
            ],
            'flatrate, some boxes charged' => [
                'flatrate_flatrate', 5.0, false, [true, false], FreeShipping::PARTIAL,
                '1 of 2 boxes ship free; Flat Rate charges 5.00 for the rest.',
            ],
            'tablerate, all units flagged, stale rate' => [
                'tablerate_bestway', 15.0, false, [true, true], FreeShipping::STALE_RATE,
                'Table Rate still charges 15.00',
            ],
            'tablerate, address flag 1 frees no unit of a qty-1 item' => [
                'tablerate_bestway', 15.0, true, [false, false], FreeShipping::PARTIAL,
                '0 of 2 boxes ship free; Table Rate charges 15.00 for the rest.',
            ],
            'tablerate, items mode partial' => [
                'tablerate_bestway', 15.0, false, [true, false], FreeShipping::PARTIAL, '1 of 2 boxes ship free',
            ],
            'freeshipping carrier' => [
                'freeshipping_freeshipping', 0.0, true, [false, false], FreeShipping::EFFECTIVE,
                'Free Shipping charges 0.00.',
            ],
            'third-party carrier charges' => [
                'vendor_express', 12.0, true, [false, false], FreeShipping::UNKNOWN_CARRIER,
                'Third-party carrier Vendor Express charges 12.00',
            ],
            'third-party carrier free' => [
                'vendor_express', 0.0, true, [false, false], FreeShipping::EFFECTIVE,
                'Vendor Express charges 0.00.',
            ],
            'rule applied but no flag' => [
                'flatrate_flatrate', 10.0, false, [false, false], FreeShipping::NOT_GRANTED,
                'core set no free-shipping flag',
            ],
        ];
    }

    #[DataProvider('verdicts')]
    public function testVerdict(
        string $method,
        float $price,
        bool $addressFlag,
        array $itemFlags,
        string $code,
        string $message
    ): void {
        $items = [];
        foreach ($itemFlags as $i => $flag) {
            $items[] = $this->item(10 + $i, ['qty' => 1, 'free_shipping' => $flag]);
        }
        $address = $this->address($items, $method, $this->rate($method, $price), $addressFlag);

        $result = $this->explain($address, [$this->granting(FreeShipping::MODE_ADDRESS)]);
        $section = $result->getFreeShipping();

        self::assertSame($code, $section->getVerdict());
        self::assertStringContainsString($message, $section->getMessage());
        self::assertSame($code, $result->getVerdicts()[0]->getFreeShippingOutcome());
        $warned = $code !== FreeShipping::EFFECTIVE;
        $context = ['verdict' => $code, 'method' => $method, 'rule_ids' => [3]];
        self::assertSame($warned, !$section->isEffective());
        self::assertSame(
            $warned ? [[FreeShippingExplainer::WARNING_CODE, $context]] : [],
            array_map(fn ($w): array => [$w->getCode(), $w->getContext()], $result->getWarnings())
        );
    }

    public function testSectionFacts(): void
    {
        $this->config = [
            FreeShippingExplainer::XML_FREE_ACTIVE => true,
            FreeShippingExplainer::XML_FREE_SUBTOTAL => '75',
        ];
        $items = [
            $this->item(10, ['qty' => 2, 'free_shipping' => 1, 'free_shipping_method' => 'flatrate_flatrate']),
            $this->item(11, ['qty' => 1, 'free_shipping' => 1, 'free_shipping_method' => 'tablerate_bestway']),
        ];
        $rate = $this->rate('flatrate_flatrate', 5.0, '2026-10-28 09:00:00');
        $address = $this->address($items, 'flatrate_flatrate', $rate, false);

        $section = $this->explain($address, [$this->granting(FreeShipping::MODE_ITEMS)], '2026-10-28 10:00:00')
            ->getFreeShipping();

        self::assertSame(FreeShipping::PARTIAL, $section->getVerdict());
        self::assertSame(
            [['id' => 3, 'name' => 'Ship free', 'mode' => FreeShipping::MODE_ITEMS]],
            $section->getRules()
        );
        self::assertFalse($section->hasAddressFlag());
        self::assertSame([['item_id' => 10, 'sku' => 'SKU10', 'qty' => 2.0]], $section->getItems());
        self::assertSame([2.0, 3.0], [$section->getFreeBoxes(), $section->getPackageQty()]);
        self::assertSame('flatrate_flatrate', $section->getMethod());
        self::assertSame(5.0, $section->getRatePrice());
        self::assertSame(FreeShipping::SEMANTICS_ITEMS, $section->getSemantics());
        self::assertTrue($section->isFreeCarrierEnabled());
        self::assertSame(75.0, $section->getFreeCarrierSubtotal());
        self::assertTrue($section->isRateOlderThanQuote());
        self::assertSame(
            [
                'verdict' => 'partial',
                'verdict_label' => 'Partially effective',
                'message' => $section->getMessage(),
                'rules' => [
                    ['id' => 3, 'name' => 'Ship free', 'mode' => 'items', 'mode_label' => 'For matching items only'],
                ],
                'address_flag' => false,
                'items' => [['item_id' => 10, 'sku' => 'SKU10', 'qty' => 2]],
                'free_boxes' => 2,
                'package_qty' => 3,
                'method' => 'flatrate_flatrate',
                'rate_price' => '5.00',
                'semantics' => 'items_only',
                'semantics_label' => 'Item flags only',
                'free_shipping_carrier' => ['enabled' => true, 'enabled_label' => 'Enabled', 'subtotal' => '75.00'],
                'rate_updated_at' => '2026-10-28 09:00:00',
                'quote_updated_at' => '2026-10-28 10:00:00',
                'rate_older_than_quote' => true,
            ],
            $section->toArray()
        );
    }

    public function testEnabledFreeShippingCarrierIsSuggestedForSelection(): void
    {
        $this->config = [FreeShippingExplainer::XML_FREE_ACTIVE => true];
        $rate = $this->rate('flatrate_flatrate', 10.0);
        $address = $this->address([$this->item(10)], 'flatrate_flatrate', $rate, true);

        $section = $this->explain($address, [$this->granting(FreeShipping::MODE_ADDRESS)])->getFreeShipping();

        self::assertSame(FreeShipping::NOT_HONOURED, $section->getVerdict());
        self::assertStringContainsString('select Free Shipping', $section->getMessage());
        self::assertNull($section->getFreeCarrierSubtotal());
    }

    public function testShipSeparatelyBundleCountsChildBoxes(): void
    {
        $free = $this->item(21, ['qty' => 2, 'free_shipping' => 1]);
        $charged = $this->item(22, ['qty' => 1]);
        $virtual = $this->item(23, ['qty' => 1, 'free_shipping' => 1], true);
        $bundle = $this->item(20, ['qty' => 3, 'has_children' => true], false, [$free, $charged, $virtual], true);
        foreach ([$free, $charged, $virtual] as $child) {
            $child->setParentItem($bundle);
        }
        $address = $this->address(
            [$bundle, $free, $charged, $virtual],
            'flatrate_flatrate',
            $this->rate('flatrate_flatrate', 5.0),
            false
        );

        $section = $this->explain($address, [])->getFreeShipping();

        self::assertSame([6.0, 9.0], [$section->getFreeBoxes(), $section->getPackageQty()]);
        self::assertSame([['item_id' => 21, 'sku' => 'SKU21', 'qty' => 6.0]], $section->getItems());
        self::assertSame(FreeShipping::PARTIAL, $section->getVerdict());
    }

    public function testTableRateSubtractsNumericFlags(): void
    {
        $numeric = $this->item(10, ['qty' => 3, 'free_shipping' => 1]);
        $child = $this->item(21, ['qty' => 1, 'free_shipping' => true]);
        $bundle = $this->item(20, ['qty' => 2, 'has_children' => true], false, [$child], true);
        $child->setParentItem($bundle);
        $address = $this->address(
            [$numeric, $bundle, $child],
            'tablerate_bestway',
            $this->rate('tablerate_bestway', 15.0),
            false
        );

        $section = $this->explain($address, [$this->granting(FreeShipping::MODE_ITEMS)])->getFreeShipping();

        self::assertSame([2.0, 5.0], [$section->getFreeBoxes(), $section->getPackageQty()]);
        self::assertSame(
            [['item_id' => 10, 'sku' => 'SKU10', 'qty' => 2.0], ['item_id' => 21, 'sku' => 'SKU21', 'qty' => 0.0]],
            $section->getItems()
        );
        self::assertSame(FreeShipping::PARTIAL, $section->getVerdict());
        self::assertSame(FreeShipping::SEMANTICS_QTY, $section->getSemantics());
    }

    public function testVirtualItemsAreNotBoxes(): void
    {
        $items = [$this->item(10, ['qty' => 1, 'free_shipping' => 1]), $this->item(11, ['qty' => 4], true)];
        $address = $this->address($items, 'flatrate_flatrate', $this->rate('flatrate_flatrate', 10.0), false);

        $section = $this->explain($address, [])->getFreeShipping();

        self::assertSame([1.0, 1.0], [$section->getFreeBoxes(), $section->getPackageQty()]);
        self::assertSame(FreeShipping::STALE_RATE, $section->getVerdict());
    }

    public function testNoMethodSelected(): void
    {
        $address = $this->address([$this->item(10)], '', null, true);

        $result = $this->explain($address, [$this->granting(FreeShipping::MODE_ADDRESS)]);

        self::assertSame(FreeShipping::NO_METHOD, $result->getFreeShipping()->getVerdict());
        self::assertNull($result->getFreeShipping()->getMethod());
        self::assertSame([], $result->getWarnings());
    }

    public function testSelectedMethodWithoutStoredRate(): void
    {
        $address = $this->address([$this->item(10)], 'flatrate_flatrate', null, true);

        $result = $this->explain($address, [$this->granting(FreeShipping::MODE_ADDRESS)]);
        $section = $result->getFreeShipping();

        self::assertSame(FreeShipping::NO_METHOD, $section->getVerdict());
        self::assertSame('flatrate_flatrate', $section->getMethod());
        self::assertNull($section->getRatePrice());
        self::assertSame(
            'flatrate_flatrate is selected but has no stored rate; re-select the shipping method to collect rates.',
            $section->getMessage()
        );
        self::assertFalse($section->isEffective());
        self::assertSame(
            [FreeShippingExplainer::WARNING_CODE],
            array_map(fn ($w): string => $w->getCode(), $result->getWarnings())
        );
    }

    public function testConfigurableChildIsNotAnExtraBox(): void
    {
        $child = $this->item(31, ['qty' => 1]);
        $parent = $this->item(30, ['qty' => 2, 'has_children' => true, 'free_shipping' => 1], false, [$child]);
        $child->setParentItem($parent);
        $address = $this->address([$parent, $child], 'flatrate_flatrate', $this->rate('flatrate_flatrate', 0.0), false);

        $section = $this->explain($address, [$this->granting(FreeShipping::MODE_ITEMS)])->getFreeShipping();

        self::assertSame([2.0, 2.0], [$section->getFreeBoxes(), $section->getPackageQty()]);
        self::assertSame([['item_id' => 30, 'sku' => 'SKU30', 'qty' => 2.0]], $section->getItems());
        self::assertSame(FreeShipping::EFFECTIVE, $section->getVerdict());
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public static function rateAges(): array
    {
        return [
            'older' => ['2026-10-28 09:00:00', '2026-10-28 10:00:00', true],
            'same time' => ['2026-10-28 10:00:00', '2026-10-28 10:00:00', false],
            'newer' => ['2026-10-28 11:00:00', '2026-10-28 10:00:00', false],
            'rate time unknown' => [null, '2026-10-28 10:00:00', false],
            'quote time unknown' => ['2026-10-28 09:00:00', null, false],
        ];
    }

    #[DataProvider('rateAges')]
    public function testRateOlderThanQuote(?string $rateUpdatedAt, ?string $quoteUpdatedAt, bool $older): void
    {
        $section = new FreeShipping(
            FreeShipping::STALE_RATE,
            'x',
            rateUpdatedAt: $rateUpdatedAt,
            quoteUpdatedAt: $quoteUpdatedAt
        );

        self::assertSame($older, $section->isRateOlderThanQuote());
    }

    public function testNothingGrantsFreeShipping(): void
    {
        $verdicts = [
            new RuleVerdict(1, 'Plain', 0, Status::APPLIED),
            $this->rejected(2),
        ];
        $rate = $this->rate('flatrate_flatrate', 5.0);
        $address = $this->address([$this->item(10)], 'flatrate_flatrate', $rate, false);

        $result = $this->explain($address, $verdicts);

        self::assertNull($result->getFreeShipping());
        self::assertSame($verdicts, $result->getVerdicts());
        self::assertSame([], $result->getWarnings());
    }

    public function testRuleWithoutFreeShippingIsNotAnnotated(): void
    {
        $rate = $this->rate('flatrate_flatrate', 0.0);
        $address = $this->address([$this->item(10)], 'flatrate_flatrate', $rate, true);

        $result = $this->explain($address, [$this->granting(FreeShipping::MODE_ADDRESS), $this->rejected(5)]);

        self::assertSame(
            [['id' => 3, 'name' => 'Ship free', 'mode' => 'address']],
            $result->getFreeShipping()->getRules()
        );
        self::assertNull($result->getVerdicts()[1]->getFreeShippingOutcome());
    }

    public function testRuleWithSkippedDiscountStillGrantsFreeShipping(): void
    {
        $rate = $this->rate('flatrate_flatrate', 0.0);
        $address = $this->address([$this->item(10)], 'flatrate_flatrate', $rate, true);

        $result = $this->explain($address, [$this->rejected(5, FreeShipping::MODE_ADDRESS)]);

        self::assertSame(
            [['id' => 5, 'name' => 'Rejected', 'mode' => 'address']],
            $result->getFreeShipping()->getRules()
        );
        self::assertSame(FreeShipping::EFFECTIVE, $result->getVerdicts()[0]->getFreeShippingOutcome());
    }

    public function testVirtualQuoteHasNoSection(): void
    {
        $quote = $this->createPartialMock(Quote::class, ['isVirtual']);
        $quote->method('isVirtual')->willReturn(true);
        $verdicts = [$this->granting(FreeShipping::MODE_ITEMS)];

        $result = (new FreeShippingExplainer($this->scopeConfig()))->explain($quote, $verdicts);

        self::assertNull($result->getFreeShipping());
        self::assertSame($verdicts, $result->getVerdicts());
    }

    public function testUnknownVerdictIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FreeShipping('maybe', 'x');
    }

    /**
     * @param Address $address
     * @param RuleVerdict[] $verdicts
     * @param string|null $updatedAt
     * @return FreeShippingExplanation
     */
    private function explain(Address $address, array $verdicts, ?string $updatedAt = null): FreeShippingExplanation
    {
        $quote = $this->createPartialMock(Quote::class, ['isVirtual', 'getShippingAddress']);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->setData(['store_id' => 1, 'updated_at' => $updatedAt]);

        return (new FreeShippingExplainer($this->scopeConfig()))->explain($quote, $verdicts);
    }

    private function scopeConfig(): ScopeConfigInterface
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnCallback(
            fn (string $path): bool => (bool)($this->config[$path] ?? false)
        );
        $config->method('getValue')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);

        return $config;
    }

    private function granting(string $mode): RuleVerdict
    {
        return new RuleVerdict(3, 'Ship free', 0, Status::APPLIED, null, null, [], 0.0, null, false, $mode);
    }

    private function rejected(int $id, ?string $mode = null): RuleVerdict
    {
        return new RuleVerdict(
            $id,
            'Rejected',
            1,
            Status::REJECTED,
            $mode === null ? Stage::CONDITIONS : Stage::DISCOUNT_STEP,
            null,
            [],
            null,
            null,
            false,
            $mode
        );
    }

    /**
     * @param Item[] $items
     * @param string $method
     * @param Rate|null $rate
     * @param bool $flag
     * @return Address
     */
    private function address(array $items, string $method, ?Rate $rate, bool $flag): Address
    {
        $address = $this->createPartialMock(Address::class, ['getAllItems', 'getShippingRateByCode']);
        $address->method('getAllItems')->willReturn($items);
        $address->method('getShippingRateByCode')->willReturnCallback(
            static fn (string $code) => $rate !== null && $code === $method ? $rate : false
        );
        $address->setData(['shipping_method' => $method, 'free_shipping' => (int)$flag]);

        return $address;
    }

    private function rate(string $code, float $price, ?string $updatedAt = null): Rate
    {
        $titles = [
            'flatrate' => 'Flat Rate',
            'tablerate' => 'Table Rate',
            'freeshipping' => 'Free Shipping',
            'vendor' => 'Vendor Express',
        ];
        $carrier = strtok($code, '_');
        $rate = $this->createPartialMock(Rate::class, []);
        $rate->setData([
            'code' => $code,
            'carrier' => $carrier,
            'carrier_title' => $titles[$carrier],
            'price' => $price,
            'updated_at' => $updatedAt,
        ]);

        return $rate;
    }

    /**
     * @param int $id
     * @param array $data
     * @param bool $virtual
     * @param Item[] $children
     * @param bool $shipSeparately
     * @return Item
     */
    private function item(
        int $id,
        array $data = [],
        bool $virtual = false,
        array $children = [],
        bool $shipSeparately = false
    ): Item {
        $product = $this->createStub(Product::class);
        $product->method('isVirtual')->willReturn($virtual);
        $item = $this->createPartialMock(Item::class, ['getProduct', 'getChildren', 'isShipSeparately']);
        $item->method('getProduct')->willReturn($product);
        $item->method('getChildren')->willReturn($children);
        $item->method('isShipSeparately')->willReturn($shipSeparately);
        $item->setData($data + ['id' => $id, 'sku' => 'SKU' . $id, 'qty' => 1]);

        return $item;
    }
}
