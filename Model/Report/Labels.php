<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

use Magento\Tax\Model\Calculation;

// phpcs:disable Magento2.Functions.StaticFunction -- called from value objects, which are built without DI.

/**
 * Translatable labels of the report codes; an unknown code is its own label.
 */
class Labels
{
    public const TAX_FLAGS = [
        'price_includes_tax',
        'shipping_includes_tax',
        'apply_after_discount',
        'discount_tax',
        'cross_border_trade_enabled',
    ];

    /**
     * Free-shipping verdict (FreeShipping::* verdict codes).
     *
     * @param string $code
     * @return string
     */
    public static function freeShippingVerdict(string $code): string
    {
        return (string)match ($code) {
            FreeShipping::EFFECTIVE => __('Effective'),
            FreeShipping::NOT_HONOURED => __('Not honoured by the carrier'),
            FreeShipping::PARTIAL => __('Partially effective'),
            FreeShipping::STALE_RATE => __('Stale shipping rate'),
            FreeShipping::UNKNOWN_CARRIER => __('Unknown carrier'),
            FreeShipping::NOT_GRANTED => __('Not granted'),
            FreeShipping::NO_METHOD => __('No shipping method'),
            default => $code,
        };
    }

    /**
     * Which free-shipping flags the carrier honours (FreeShipping::SEMANTICS_*).
     *
     * @param string $code
     * @return string
     */
    public static function semantics(string $code): string
    {
        return (string)match ($code) {
            FreeShipping::SEMANTICS_ITEMS => __('Item flags only'),
            FreeShipping::SEMANTICS_ADDRESS => __('Address flag'),
            FreeShipping::SEMANTICS_QTY => __('Free item quantity'),
            FreeShipping::SEMANTICS_UNKNOWN => __('Unknown'),
            default => $code,
        };
    }

    /**
     * Free-shipping granting mode of a rule (FreeShipping::MODE_*), as in the rule form.
     *
     * @param string $code
     * @return string
     */
    public static function freeShippingMode(string $code): string
    {
        return (string)match ($code) {
            FreeShipping::MODE_ITEMS => __('For matching items only'),
            FreeShipping::MODE_ADDRESS => __('For shipment with matching items'),
            default => $code,
        };
    }

    /**
     * Price layer (PriceLayer::CODE_*).
     *
     * @param string $code
     * @return string
     */
    public static function layer(string $code): string
    {
        return (string)match ($code) {
            PriceLayer::CODE_BASE => __('Base price'),
            PriceLayer::CODE_SPECIAL => __('Special price'),
            PriceLayer::CODE_TIER => __('Tier price'),
            PriceLayer::CODE_CATALOG_RULE => __('Catalog price rule'),
            PriceLayer::CODE_OPTIONS => __('Custom options'),
            default => $code,
        };
    }

    /**
     * Price layer state (PriceLayer::STATE_*).
     *
     * @param string $state
     * @return string
     */
    public static function layerState(string $state): string
    {
        return (string)match ($state) {
            PriceLayer::STATE_WON => __('Won'),
            PriceLayer::STATE_ACTIVE => __('Active'),
            PriceLayer::STATE_INACTIVE => __('Inactive'),
            default => $state,
        };
    }

    /**
     * Rule status (Status::*).
     *
     * @param string $code
     * @return string
     */
    public static function status(string $code): string
    {
        return (string)match ($code) {
            Status::APPLIED => __('Applied'),
            Status::REJECTED => __('Rejected'),
            Status::NOT_CANDIDATE => __('Not a candidate'),
            default => $code,
        };
    }

    /**
     * Rule rejection stage (Stage::*).
     *
     * @param string $code
     * @return string
     */
    public static function stage(string $code): string
    {
        return (string)match ($code) {
            Stage::WEBSITE => __('Website'),
            Stage::CUSTOMER_GROUP => __('Customer group'),
            Stage::DATE => __('Date range'),
            Stage::INACTIVE => __('Rule inactive'),
            Stage::COUPON => __('Coupon'),
            Stage::COUPON_USAGE => __('Coupon usage limit'),
            Stage::CUSTOMER_USAGE => __('Uses per customer'),
            Stage::CONDITIONS => __('Conditions'),
            Stage::ACTIONS => __('Actions'),
            Stage::DISCOUNT_STEP => __('Discount step'),
            Stage::DISCOUNT_QTY => __('Maximum qty discount'),
            Stage::STOPPED_BY => __('Discarded by an earlier rule'),
            Stage::ITEM_EXCLUDED => __('Item excluded'),
            default => $code,
        };
    }

    /**
     * Tax config flag (self::TAX_FLAGS).
     *
     * @param string $code
     * @return string
     */
    public static function taxFlag(string $code): string
    {
        return (string)match ($code) {
            'price_includes_tax' => __('Catalog prices include tax'),
            'shipping_includes_tax' => __('Shipping prices include tax'),
            'apply_after_discount' => __('Tax applied after discount'),
            'discount_tax' => __('Discount applied on prices including tax'),
            'cross_border_trade_enabled' => __('Cross border trade'),
            default => $code,
        };
    }

    /**
     * Tax calculation source (tax/calculation/based_on).
     *
     * @param string $code
     * @return string
     */
    public static function taxBasedOn(string $code): string
    {
        return (string)match ($code) {
            'shipping' => __('Shipping address'),
            'billing' => __('Billing address'),
            'origin' => __('Shipping origin'),
            default => $code,
        };
    }

    /**
     * Tax calculation algorithm (tax/calculation/algorithm).
     *
     * @param string $code
     * @return string
     */
    public static function taxAlgorithm(string $code): string
    {
        return (string)match ($code) {
            Calculation::CALC_UNIT_BASE => __('Unit price'),
            Calculation::CALC_ROW_BASE => __('Row total'),
            Calculation::CALC_TOTAL_BASE => __('Total'),
            default => $code,
        };
    }

    /**
     * Carrier enabled / disabled.
     *
     * @param bool $enabled
     * @return string
     */
    public static function enabled(bool $enabled): string
    {
        return (string)($enabled ? __('Enabled') : __('Disabled'));
    }

    /**
     * Address the tax rates were looked up for (tax based-on value or TaxExplainer::SOURCE_FALLBACK).
     *
     * @param string $code
     * @return string
     */
    public static function taxAddressSource(string $code): string
    {
        return $code === 'fallback' ? (string)__('Fallback address') : self::taxBasedOn($code);
    }

    /**
     * Quote total (Totals::CODES).
     *
     * @param string $code
     * @return string
     */
    public static function total(string $code): string
    {
        return (string)match ($code) {
            Totals::SUBTOTAL => __('Subtotal'),
            Totals::DISCOUNT => __('Discount'),
            Totals::SHIPPING => __('Shipping'),
            Totals::TAX => __('Tax'),
            Totals::GRAND_TOTAL => __('Grand Total'),
            default => $code,
        };
    }
}
