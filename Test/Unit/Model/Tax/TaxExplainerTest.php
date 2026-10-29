<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Tax;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\Labels;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\TaxLine;
use DmLab\PriceExplain\Model\Tax\TaxExplainer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Tax\Api\Data\TaxClassInterface;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TaxExplainerTest extends TestCase
{
    private const TAXABLE = 2;
    private const RETAIL = 3;
    private const SHIPPING_CLASS = 4;
    private const DELETED = 99;

    private const CLASS_NAMES = [
        self::TAXABLE => 'Taxable Goods',
        self::RETAIL => 'Retail Customer',
        self::SHIPPING_CLASS => 'Shipping',
    ];

    /**
     * @var array<string, mixed> tax config values
     */
    private array $config = [];

    /**
     * @var string tax/calculation/based_on
     */
    private string $basedOn = 'shipping';

    /**
     * @var array{country_id: string|null, region_id: int|null, postcode: string|null}
     */
    private array $request = ['country_id' => 'US', 'region_id' => 12, 'postcode' => '90001'];

    /**
     * @var array<int, array[]> product class ID => rates returned by the lookup
     */
    private array $lookupRates = [];

    /**
     * @var array arguments of getRateRequest()
     */
    private array $requestArgs = [];

    public function testTaxedItemSplitsCombinedRowAndListsAddressTaxes(): void
    {
        $applied = [$this->appliedRow(8.0, [['US-CA', 'California', 6.0], ['US-LA', 'Los Angeles', 2.0]])];
        $item = $this->item(1, ['tax_amount' => 8.0, 'applied_taxes' => $applied]);
        $address = $this->address(['country_id' => 'US'], $applied);

        $tax = $this->explain($this->quote([$item], $address));

        $this->assertSame(self::CLASS_NAMES[self::RETAIL], $tax->getCustomerTaxClass());
        $line = $tax->toArray()['lines'][0];
        $this->assertSame(
            [
                'type' => TaxLine::TYPE_ITEM,
                'item_id' => 1,
                'label' => 'SKU1',
                'tax_class' => 'Taxable Goods',
                'amount' => '8.00',
                'rates' => [
                    ['code' => 'US-CA', 'title' => 'California', 'percent' => '6', 'amount' => '6.00'],
                    ['code' => 'US-LA', 'title' => 'Los Angeles', 'percent' => '2', 'amount' => '2.00'],
                ],
                'reason' => null,
            ],
            $line
        );
        $this->assertSame($line['rates'], $tax->toArray()['applied_taxes']);
    }

    /**
     * @return array<string, array{array, int, string}>
     */
    public static function untaxedProvider(): array
    {
        return [
            'product class none' => [[], 0, 'The product tax class is "None".'],
            'rates applied, amount zero' => [
                ['applied_taxes' => [['amount' => 0, 'rates' => [['code' => 'US', 'title' => 'US', 'percent' => 5]]]]],
                self::TAXABLE,
                'Tax rates apply but the taxable amount is zero.',
            ],
            'no matching rate' => [
                [],
                self::TAXABLE,
                'No tax rate matches US, region 12, 90001 for product class "Taxable Goods" '
                . 'and customer class "Retail Customer".',
            ],
            'zero-rate rule' => [
                ['lookup' => [['US-ZERO', 0.0]]],
                self::TAXABLE,
                'A zero-rate tax rule applies: US-ZERO.',
            ],
            'rate matches but core applied none' => [
                ['lookup' => [['US-CA', 6.0]]],
                self::TAXABLE,
                'Tax rates match (US-CA) but core applied none.',
            ],
            'deleted product class' => [
                [],
                self::DELETED,
                'No tax rate matches US, region 12, 90001 for product class "#99" '
                . 'and customer class "Retail Customer".',
            ],
        ];
    }

    /**
     * @param array $setup applied_taxes on the item, lookup rates [[code, percent]]
     * @param int $classId
     * @param string $reason
     */
    #[DataProvider('untaxedProvider')]
    public function testUntaxedItemIsExplained(array $setup, int $classId, string $reason): void
    {
        $this->lookupRates[$classId] = array_map(
            static fn (array $r): array => ['code' => $r[0], 'title' => $r[0], 'percent' => $r[1]],
            $setup['lookup'] ?? []
        );
        $item = $this->item(1, ['tax_amount' => 0, 'applied_taxes' => $setup['applied_taxes'] ?? []], $classId);

        $line = $this->explain($this->quote([$item], $this->address()))->getLines()[0];

        $this->assertSame(0.0, $line->getAmount());
        $this->assertSame($reason, $line->getReason());
    }

    public function testUntaxedWithoutCustomerClassOrCountry(): void
    {
        $item = $this->item(1, ['tax_amount' => 0]);

        $line = $this->explain($this->quote([$item], $this->address(), customerClass: 0))->getLines()[0];
        $this->assertSame('The customer group has no tax class.', $line->getReason());

        $this->request['country_id'] = null;
        $line = $this->explain($this->quote([$item], $this->address()))->getLines()[0];
        $this->assertSame('No country to look up tax rates for.', $line->getReason());
    }

    public function testShippingLineTaxedFromItemsAppliedTaxes(): void
    {
        $this->config['shipping_class'] = self::SHIPPING_CLASS;
        $address = $this->address([
            'shipping_amount' => 10.0,
            'shipping_tax_amount' => 0.8,
            'items_applied_taxes' => ['shipping' => [$this->appliedRow(0.8, [['US-CA', 'California', 8.0]])]],
        ]);

        $line = $this->explain($this->quote([], $address))->toArray()['lines'][0];

        $this->assertSame(TaxLine::TYPE_SHIPPING, $line['type']);
        $this->assertNull($line['item_id']);
        $this->assertSame('Shipping', $line['tax_class']);
        $this->assertSame('0.80', $line['amount']);
        $this->assertSame(
            [['code' => 'US-CA', 'title' => 'California', 'percent' => '8', 'amount' => '0.80']],
            $line['rates']
        );
        $this->assertNull($line['reason']);
    }

    public function testUntaxedShippingReasons(): void
    {
        $line = $this->explain($this->quote([], $this->address()))->getLines()[0];
        $this->assertSame('No shipping charge.', $line->getReason());

        $line = $this->explain($this->quote([], $this->address(['shipping_amount' => 5.0])))->getLines()[0];
        $this->assertSame(
            'No shipping tax class is configured (tax/classes/shipping_tax_class).',
            $line->getReason()
        );

        $this->config['shipping_class'] = self::SHIPPING_CLASS;
        $line = $this->explain($this->quote([], $this->address(['shipping_amount' => 5.0])))->getLines()[0];
        $this->assertStringStartsWith('No tax rate matches US', $line->getReason());
    }

    public function testVirtualQuoteUsesBillingAddressAndHasNoShippingLine(): void
    {
        $billing = $this->address(['country_id' => 'DE']);
        $quote = $this->quote([$this->item(1, ['tax_amount' => 1.9])], $this->address(), $billing, virtual: true);
        $this->basedOn = 'billing';

        $tax = $this->explain($quote);

        $this->assertCount(1, $tax->getLines());
        $this->assertSame(TaxLine::TYPE_ITEM, $tax->getLines()[0]->getType());
        $this->assertSame($billing, $this->requestArgs[0]);
        $this->assertSame($billing, $this->requestArgs[1]);
        $this->assertSame([self::RETAIL, 1, 7], array_slice($this->requestArgs, 2));
        $this->assertSame('billing', $tax->getAddress()['source']);
    }

    /**
     * @return array<string, array{string, array, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'shipping address' => ['shipping', ['country_id' => 'US'], 'shipping'],
            'shipping without country falls back' => ['shipping', [], TaxExplainer::SOURCE_FALLBACK],
            'billing without country falls back' => ['billing', ['country_id' => 'US'], TaxExplainer::SOURCE_FALLBACK],
            'origin' => ['origin', [], 'origin'],
        ];
    }

    /**
     * @param string $basedOn
     * @param array $shipping shipping address data (billing has no country)
     * @param string $source
     */
    #[DataProvider('sourceProvider')]
    public function testAddressSource(string $basedOn, array $shipping, string $source): void
    {
        $this->basedOn = $basedOn;

        $tax = $this->explain($this->quote([], $this->address($shipping)));

        $this->assertSame($basedOn, $tax->getBasedOn());
        $this->assertSame(
            ['source' => $source, 'country_id' => 'US', 'region_id' => 12, 'postcode' => '90001'],
            $tax->getAddress()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function flagProvider(): array
    {
        return array_map(static fn (string $flag): array => [$flag], [
            'price_includes_tax' => 'price_includes_tax',
            'shipping_includes_tax' => 'shipping_includes_tax',
            'apply_after_discount' => 'apply_after_discount',
            'discount_tax' => 'discount_tax',
            'cross_border_trade_enabled' => 'cross_border_trade_enabled',
        ]);
    }

    /**
     * @param string $flag
     */
    #[DataProvider('flagProvider')]
    public function testEachConfigFlagIsSurfaced(string $flag): void
    {
        $this->config[$flag] = true;
        $this->config['algorithm'] = 'ROW_BASE_CALCULATION';

        $tax = $this->explain($this->quote([], $this->address()));

        $expected = array_fill_keys(array_keys(self::flagProvider()), false);
        $expected[$flag] = true;
        $this->assertSame($expected, $tax->getFlags());
        $this->assertSame(Labels::TAX_FLAGS, array_keys($tax->getFlags()));
        $this->assertSame('ROW_BASE_CALCULATION', $tax->getAlgorithm());
    }

    public function testConfigurableUsesChildClassAndDynamicBundleListsChildren(): void
    {
        $child = $this->item(11, [], self::TAXABLE);
        $configurable = $this->item(1, ['has_children' => true], 0, 'configurable', [$child]);
        $bundleChildren = [$this->item(21, ['tax_amount' => 1.0]), $this->item(22, ['tax_amount' => 2.0])];
        $bundle = $this->item(2, ['has_children' => true], 0, 'bundle', $bundleChildren, childrenCalculated: true);
        $child->setParentItem($configurable);
        array_map(static fn (Item $c) => $c->setParentItem($bundle), $bundleChildren);

        $lines = $this->explain($this->quote([$configurable, $bundle], $this->address(), virtual: true))->getLines();

        $this->assertSame([1, 21, 22], array_map(static fn (TaxLine $l): ?int => $l->getItemId(), $lines));
        $this->assertSame(
            ['SKU1 → SKU11', 'SKU2 → SKU21', 'SKU2 → SKU22'],
            array_map(static fn (TaxLine $l): string => $l->getLabel(), $lines)
        );
        $this->assertSame('Taxable Goods', $lines[0]->getTaxClass());
        $this->assertSame(2.0, $lines[2]->getAmount());
    }

    /**
     * @param Quote $quote
     * @return TaxBreakdown
     */
    private function explain(Quote $quote): TaxBreakdown
    {
        $config = $this->createStub(Config::class);
        $config->method('getAlgorithm')
            ->willReturnCallback(fn () => $this->config['algorithm'] ?? 'TOTAL_BASE_CALCULATION');
        $config->method('getShippingTaxClass')->willReturnCallback(fn () => $this->config['shipping_class'] ?? 0);
        $config->method('priceIncludesTax')->willReturnCallback(fn () => $this->config['price_includes_tax'] ?? false);
        $config->method('shippingPriceIncludesTax')
            ->willReturnCallback(fn () => $this->config['shipping_includes_tax'] ?? false);
        $config->method('applyTaxAfterDiscount')
            ->willReturnCallback(fn () => $this->config['apply_after_discount'] ?? false);
        $config->method('discountTax')->willReturnCallback(fn () => $this->config['discount_tax'] ?? false);
        $config->method('crossBorderTradeEnabled')
            ->willReturnCallback(fn () => $this->config['cross_border_trade_enabled'] ?? false);

        $calculation = $this->createStub(Calculation::class);
        $calculation->method('getRateRequest')->willReturnCallback(function (...$args): DataObject {
            $this->requestArgs = $args;

            return new DataObject($this->request + ['customer_class_id' => $args[2], 'store' => $args[3]]);
        });
        $calculation->method('getAppliedRates')->willReturnCallback(
            fn (DataObject $request): array => ($rates = $this->lookupRates[$request->getProductClassId()] ?? [])
                ? [['rates' => $rates, 'percent' => array_sum(array_column($rates, 'percent'))]]
                : []
        );

        $repository = $this->createStub(TaxClassRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function (int $id): TaxClassInterface {
            if (!isset(self::CLASS_NAMES[$id])) {
                throw new NoSuchEntityException(__('No such class.'));
            }
            $class = $this->createStub(TaxClassInterface::class);
            $class->method('getClassName')->willReturn(self::CLASS_NAMES[$id]);

            return $class;
        });

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn () => $this->basedOn);

        return (new TaxExplainer($config, $calculation, $repository, $scopeConfig, new ItemLabel()))->explain($quote);
    }

    /**
     * @param Item[] $items
     * @param Address $shipping
     * @param Address|null $billing defaults to an address without country
     * @param bool $virtual
     * @param int $customerClass
     * @return Quote
     */
    private function quote(
        array $items,
        Address $shipping,
        ?Address $billing = null,
        bool $virtual = false,
        int $customerClass = self::RETAIL
    ): Quote {
        $quote = $this->createPartialMock(
            Quote::class,
            ['isVirtual', 'getShippingAddress', 'getBillingAddress', 'getAllVisibleItems', 'getCustomerTaxClassId']
        );
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('getShippingAddress')->willReturn($shipping);
        $quote->method('getBillingAddress')->willReturn($billing ?? $this->address());
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getCustomerTaxClassId')->willReturn($customerClass);
        $quote->setData(['store_id' => 1, 'customer_id' => 7]);

        return $quote;
    }

    /**
     * @param array $data
     * @param array[] $appliedTaxes address-level applied taxes
     * @return Address
     */
    private function address(array $data = [], array $appliedTaxes = []): Address
    {
        $address = $this->createPartialMock(Address::class, ['getAppliedTaxes', 'isAddressMultilineAttribute']);
        $address->method('getAppliedTaxes')->willReturn($appliedTaxes);
        $address->setData($data);

        return $address;
    }

    /**
     * @param int $id
     * @param array $data
     * @param int $classId product tax class
     * @param string $typeId
     * @param Item[] $children
     * @param bool $childrenCalculated
     * @return Item
     */
    private function item(
        int $id,
        array $data = [],
        int $classId = self::TAXABLE,
        string $typeId = 'simple',
        array $children = [],
        bool $childrenCalculated = false
    ): Item {
        $item = $this->createPartialMock(Item::class, ['isChildrenCalculated', 'getChildren']);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        $item->method('getChildren')->willReturn($children);
        $item->setData($data + [
            'id' => $id,
            'sku' => 'SKU' . $id,
            'product' => new DataObject(['type_id' => $typeId, 'tax_class_id' => $classId]),
        ]);

        return $item;
    }

    /**
     * Applied tax row in core's array format.
     *
     * @param float $amount
     * @param array[] $rates [[code, title, percent]]
     * @return array
     */
    private function appliedRow(float $amount, array $rates): array
    {
        return [
            'amount' => $amount,
            'base_amount' => $amount,
            'percent' => array_sum(array_column($rates, 2)),
            'id' => implode('', array_column($rates, 0)),
            'rates' => array_map(
                static fn (array $r): array => ['code' => $r[0], 'title' => $r[1], 'percent' => $r[2]],
                $rates
            ),
        ];
    }
}
