<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Report;

use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\Labels;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\Totals;
use Magento\Tax\Model\Calculation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LabelsTest extends TestCase
{
    /**
     * @return array<string, array{callable, string[]}>
     */
    public static function groupProvider(): array
    {
        return [
            'free-shipping verdicts' => [Labels::freeShippingVerdict(...), FreeShipping::ALL],
            'carrier semantics' => [Labels::semantics(...), self::constants(FreeShipping::class, 'SEMANTICS_')],
            'granting modes' => [Labels::freeShippingMode(...), array_values(FreeShipping::RULE_MODES)],
            'price layers' => [Labels::layer(...), self::constants(PriceLayer::class, 'CODE_')],
            'price layer states' => [Labels::layerState(...), self::constants(PriceLayer::class, 'STATE_')],
            'rule statuses' => [Labels::status(...), Status::ALL],
            'rule stages' => [Labels::stage(...), Stage::ALL],
            'tax flags' => [Labels::taxFlag(...), Labels::TAX_FLAGS],
            'tax based on' => [Labels::taxBasedOn(...), ['shipping', 'billing', 'origin']],
            'tax address sources' => [Labels::taxAddressSource(...), ['shipping', 'billing', 'origin', 'fallback']],
            'tax algorithms' => [Labels::taxAlgorithm(...), [
                Calculation::CALC_UNIT_BASE,
                Calculation::CALC_ROW_BASE,
                Calculation::CALC_TOTAL_BASE,
            ]],
            'totals' => [Labels::total(...), Totals::CODES],
        ];
    }

    /**
     * @param callable $label
     * @param string[] $codes
     */
    #[DataProvider('groupProvider')]
    public function testEveryCodeHasItsOwnLabelAndUnknownCodesPassThrough(callable $label, array $codes): void
    {
        self::assertNotEmpty($codes);
        $labels = array_map($label, $codes);
        foreach ($codes as $i => $code) {
            self::assertNotSame($code, $labels[$i], $code);
            self::assertNotSame('', $labels[$i], $code);
        }
        self::assertSame($labels, array_values(array_unique($labels)));
        self::assertSame('vendor_code', $label('vendor_code'));
    }

    public function testEnabled(): void
    {
        self::assertSame(['Enabled', 'Disabled'], [Labels::enabled(true), Labels::enabled(false)]);
    }

    public function testTaxAddressLabelNamesTheSourceAndSkipsEmptyParts(): void
    {
        $label = static fn (array $address): string => (new TaxBreakdown('shipping', 'x', null, $address))
            ->getAddressLabel();

        self::assertSame(
            'US, region 57, 78701 (Shipping address)',
            $label(['source' => 'shipping', 'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701'])
        );
        self::assertSame(
            'DE (Fallback address)',
            $label(['source' => 'fallback', 'country_id' => 'DE', 'region_id' => null, 'postcode' => null])
        );
        self::assertSame('— (Shipping origin)', $label(['source' => 'origin', 'country_id' => null]));
        self::assertSame('', $label([]));
    }

    public function testPriceLayerState(): void
    {
        self::assertSame(PriceLayer::STATE_WON, (new PriceLayer('base', 1.0, true, true))->getState());
        self::assertSame(PriceLayer::STATE_ACTIVE, (new PriceLayer('base', 1.0))->getState());
        self::assertSame(PriceLayer::STATE_INACTIVE, (new PriceLayer('tier', null, false))->getState());
    }

    public function testToArrayKeepsCodesAndAddsLabels(): void
    {
        $layer = (new PriceLayer(PriceLayer::CODE_CATALOG_RULE, 9.0, true, true))->toArray();
        self::assertSame(['catalog_rule', 'Catalog price rule'], [$layer['code'], $layer['label']]);
        self::assertSame(['won', 'Won'], [$layer['state'], $layer['state_label']]);

        $outcome = (new RuleItemOutcome(1, 'SKU', false, Stage::ACTIONS))->toArray();
        self::assertSame(['actions', 'Actions'], [$outcome['stage'], $outcome['stage_label']]);
        self::assertNull((new RuleItemOutcome(1, 'SKU', true))->toArray()['stage_label']);

        $rule = (new RuleVerdict(1, 'R', 0, Status::NOT_CANDIDATE, Stage::WEBSITE))
            ->withFreeShippingOutcome(FreeShipping::NOT_HONOURED)
            ->toArray();
        self::assertSame(['not_candidate', 'Not a candidate'], [$rule['status'], $rule['status_label']]);
        self::assertSame(['website', 'Website'], [$rule['stage'], $rule['stage_label']]);
        self::assertSame(
            ['not_honoured_by_carrier', 'Not honoured by the carrier'],
            [$rule['free_shipping_outcome'], $rule['free_shipping_outcome_label']]
        );
        self::assertSame([null, null], [$rule['free_shipping'], $rule['free_shipping_label']]);

        $tax = (new TaxBreakdown(
            'origin',
            'UNIT_BASE_CALCULATION',
            null,
            [],
            ['discount_tax' => true, 'vendor_flag' => false]
        ))->toArray();
        self::assertSame(['discount_tax' => true, 'vendor_flag' => false], $tax['flags']);
        self::assertSame(
            ['discount_tax' => 'Discount applied on prices including tax', 'vendor_flag' => 'vendor_flag'],
            $tax['flag_labels']
        );
        self::assertSame(['origin', 'Shipping origin'], [$tax['based_on'], $tax['based_on_label']]);
        self::assertSame(['UNIT_BASE_CALCULATION', 'Unit price'], [$tax['algorithm'], $tax['algorithm_label']]);
        self::assertSame('', $tax['address_label']);

        $section = (new FreeShipping(
            FreeShipping::STALE_RATE,
            'm',
            [['id' => 2, 'name' => 'Free', 'mode' => FreeShipping::MODE_ADDRESS]],
            semantics: FreeShipping::SEMANTICS_QTY,
            freeCarrierEnabled: true
        ))->toArray();
        self::assertSame(['stale_rate', 'Stale shipping rate'], [$section['verdict'], $section['verdict_label']]);
        self::assertSame('For shipment with matching items', $section['rules'][0]['mode_label']);
        self::assertSame('address', $section['rules'][0]['mode']);
        self::assertSame(['free_qty', 'Free item quantity'], [$section['semantics'], $section['semantics_label']]);
        self::assertSame([true, 'Enabled'], array_values(array_intersect_key(
            $section['free_shipping_carrier'],
            ['enabled' => 0, 'enabled_label' => 0]
        )));
    }

    /**
     * @param class-string $class
     * @param string $prefix
     * @return string[]
     */
    private static function constants(string $class, string $prefix): array
    {
        return array_values(array_filter(
            (new \ReflectionClass($class))->getConstants(),
            static fn ($value, string $name): bool => str_starts_with($name, $prefix) && is_string($value),
            ARRAY_FILTER_USE_BOTH
        ));
    }
}
