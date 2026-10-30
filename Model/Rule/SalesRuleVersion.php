<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use Composer\InstalledVersions;

/**
 * Core rule-processing behaviours that differ across installed magento/module-sales-rule versions.
 */
class SalesRuleVersion
{
    private const PACKAGE = 'magento/module-sales-rule';

    /**
     * @param string|null $version module-sales-rule version or Composer version ranges; null reads the installed one
     */
    public function __construct(
        private readonly ?string $version = null
    ) {
    }

    /**
     * Discount::collect skips items below the discount step; earlier cores apply the rule with zero discount.
     *
     * @return bool
     */
    public function skipsBelowDiscountStep(): bool
    {
        return $this->isAtLeast('101.2.8');
    }

    /**
     * RulesApplier discounts a matching children-calculated parent before its children; earlier cores do the reverse.
     *
     * @return bool
     */
    public function discountsParentFirst(): bool
    {
        return $this->isAtLeast('101.2.8');
    }

    /**
     * Discount::collect lowers subtotal_with_discount after each rule; earlier cores keep it undiscounted.
     *
     * @return bool
     */
    public function chainsSubtotal(): bool
    {
        return $this->isAtLeast('101.2.9');
    }

    /**
     * Utility::canProcessRule validates item-restricted rules against eligible items' totals; earlier cores use all.
     *
     * @return bool
     */
    public function countsEligibleItemsOnly(): bool
    {
        return $this->isAtLeast('101.2.9');
    }

    /**
     * Whether module-sales-rule is at least $since; unknown and dev versions are taken as current core.
     *
     * A replaced package (Mage-OS) has no version, only the ranges its replacer declares.
     *
     * @param string $since
     * @return bool
     */
    private function isAtLeast(string $since): bool
    {
        $version = $this->version ?? (
            InstalledVersions::isInstalled(self::PACKAGE)
                ? InstalledVersions::getVersion(self::PACKAGE) ?? InstalledVersions::getVersionRanges(self::PACKAGE)
                : null
        );
        if ($version === null || !preg_match('/^\d+\.\d+\.\d+/', $version, $match)) {
            return true;
        }

        return version_compare($match[0], $since, '>=');
    }
}
