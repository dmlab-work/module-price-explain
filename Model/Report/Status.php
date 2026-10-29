<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Overall verdict of a cart price rule for the quote.
 */
class Status
{
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';
    /** Hidden by the pre-filter for another website; shown only with "all rules". */
    public const NOT_CANDIDATE = 'not_candidate';

    public const ALL = [self::APPLIED, self::REJECTED, self::NOT_CANDIDATE];
}
