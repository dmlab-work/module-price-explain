<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Rule;

use DmLab\PriceExplain\Model\Rule\SalesRuleVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalesRuleVersionTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function versionProvider(): array
    {
        return [
            '2.4.7' => ['101.2.7', false, false],
            '2.4.7 patch' => ['101.2.7-p3', false, false],
            '2.4.7 normalized' => ['101.2.7.0', false, false],
            '2.4.8' => ['101.2.8', true, false],
            '2.4.8 patch' => ['101.2.8-p1', true, false],
            'replaced by Mage-OS' => ['101.2.8-p3 || 101.2.8', true, false],
            'replaced by wildcard' => ['*', true, true],
            '2.4.9 normalized' => ['101.2.9.0', true, true],
            'two-digit patch' => ['101.2.10', true, true],
            'minor bump' => ['101.3.0', true, true],
            'dev branch' => ['dev-main', true, true],
        ];
    }

    /**
     * @param string $version
     * @param bool $since248
     * @param bool $since249
     */
    #[DataProvider('versionProvider')]
    public function testBehavioursFollowTheSalesRuleVersion(string $version, bool $since248, bool $since249): void
    {
        $salesRule = new SalesRuleVersion($version);

        self::assertSame(
            [$since248, $since248, $since249, $since249],
            [
                $salesRule->skipsBelowDiscountStep(),
                $salesRule->discountsParentFirst(),
                $salesRule->chainsSubtotal(),
                $salesRule->countsEligibleItemsOnly(),
            ]
        );
    }
}
