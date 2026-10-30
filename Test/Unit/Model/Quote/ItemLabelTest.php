<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Quote;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ItemLabelTest extends TestCase
{
    public function testSimpleItemUsesItsSku(): void
    {
        self::assertSame('24-MB01', (new ItemLabel())->get($this->item('24-MB01', '24-MB01')));
    }

    public function testConfigurableShowsParentAndChosenChild(): void
    {
        // Core stores the child SKU on the configurable item itself.
        $parent = $this->item('MP01-32-Black', 'MP01');
        $child = $this->item('MP01-32-Black', 'MP01-32-Black', $parent);

        self::assertSame('MP01 → MP01-32-Black', (new ItemLabel())->get($parent));
        self::assertSame('MP01 → MP01-32-Black', (new ItemLabel())->get($child));
        self::assertFalse((new ItemLabel())->isChildrenCalculated($parent));
    }

    public function testDynamicBundleKeepsOwnSkuAndChildrenShowParent(): void
    {
        $bundle = $this->item('24-WG080-24-WG084', '24-WG080', null, 0);
        $first = $this->item('24-WG084', '24-WG084', $bundle);
        $this->item('24-WG086', '24-WG086', $bundle);

        self::assertSame('24-WG080', (new ItemLabel())->get($bundle));
        self::assertSame('24-WG080 → 24-WG084', (new ItemLabel())->get($first));
        self::assertTrue((new ItemLabel())->isChildrenCalculated($bundle));
        self::assertTrue((new ItemLabel())->isChildrenCalculated($first));
    }

    public function testFixedBundleWithOneSelectionShowsIt(): void
    {
        $bundle = $this->item('BNDL', 'BNDL', null, 1);
        $this->item('A', 'A', $bundle);

        self::assertSame('BNDL → A', (new ItemLabel())->get($bundle));
        self::assertFalse((new ItemLabel())->isChildrenCalculated($bundle));
    }

    public function testFallsBackToItemSkuWithoutProduct(): void
    {
        $item = $this->item('NOPROD', null);

        self::assertSame('NOPROD', (new ItemLabel())->get($item));
        self::assertFalse((new ItemLabel())->isChildrenCalculated($item));
    }

    /**
     * @param string $sku item SKU
     * @param string|null $productSku product's own SKU; null = no product
     * @param Item|null $parent
     * @param int|null $priceType 0 = children calculated
     * @return Item
     */
    private function item(string $sku, ?string $productSku, ?Item $parent = null, ?int $priceType = null): Item
    {
        $item = $this->createPartialMock(Item::class, $productSku === null ? ['getProduct'] : []);
        $item->setData('sku', $sku);
        if ($productSku === null) {
            $item->method('getProduct')->willReturn(null);
        } else {
            $item->setData('product', new DataObject(['sku' => $productSku, 'price_type' => $priceType]));
        }
        $item->setParentItem($parent);

        return $item;
    }
}
