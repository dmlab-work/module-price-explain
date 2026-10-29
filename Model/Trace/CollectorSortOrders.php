<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Trace;

use Magento\Sales\Model\Config;

/**
 * sort_order of each quote totals collector as merged from every sales.xml.
 */
class CollectorSortOrders
{
    /**
     * @var array<string, int>|null
     */
    private ?array $sortOrders = null;

    /**
     * @param Config $salesConfig
     */
    public function __construct(
        private readonly Config $salesConfig
    ) {
    }

    /**
     * Sort order of the collector, null when sales.xml defines none.
     *
     * @param string $code
     * @return int|null
     */
    public function get(string $code): ?int
    {
        if ($this->sortOrders === null) {
            $this->sortOrders = [];
            foreach ((array)$this->salesConfig->getGroupTotals('quote', 'totals') as $totalCode => $total) {
                if (is_array($total) && isset($total['sort_order']) && is_numeric($total['sort_order'])) {
                    $this->sortOrders[(string)$totalCode] = (int)$total['sort_order'];
                }
            }
        }

        return $this->sortOrders[$code] ?? null;
    }
}
