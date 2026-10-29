<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Price;

use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\Warning;

/**
 * Item price section of the report plus unexplained-delta warnings.
 */
class PricesExplanation
{
    /**
     * @param ItemPrice[] $items
     * @param Warning[] $warnings
     */
    public function __construct(
        private readonly array $items,
        private readonly array $warnings
    ) {
    }

    /**
     * Price layers per visible quote item.
     *
     * @return ItemPrice[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Unexplained-delta warnings.
     *
     * @return Warning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
