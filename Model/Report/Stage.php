<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Stage at which a cart price rule was rejected for the quote or an item.
 */
class Stage
{
    public const WEBSITE = 'website';
    public const CUSTOMER_GROUP = 'customer_group';
    public const DATE = 'date';
    public const INACTIVE = 'inactive';
    public const COUPON = 'coupon';
    public const COUPON_USAGE = 'coupon_usage';
    public const CUSTOMER_USAGE = 'customer_usage';
    public const CONDITIONS = 'conditions';
    public const ACTIONS = 'actions';
    public const DISCOUNT_STEP = 'discount_step';
    public const DISCOUNT_QTY = 'discount_qty';
    public const STOPPED_BY = 'stopped_by';
    public const ITEM_EXCLUDED = 'item_excluded';

    public const ALL = [
        self::WEBSITE,
        self::CUSTOMER_GROUP,
        self::DATE,
        self::INACTIVE,
        self::COUPON,
        self::COUPON_USAGE,
        self::CUSTOMER_USAGE,
        self::CONDITIONS,
        self::ACTIONS,
        self::DISCOUNT_STEP,
        self::DISCOUNT_QTY,
        self::STOPPED_BY,
        self::ITEM_EXCLUDED,
    ];
}
