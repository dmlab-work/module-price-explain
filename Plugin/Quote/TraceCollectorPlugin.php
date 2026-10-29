<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Plugin\Quote;

use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Trace\CollectorSortOrders;
use DmLab\PriceExplain\Model\Trace\Tracer;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

/**
 * Records the amounts each totals collector changes while the tracer is active.
 */
class TraceCollectorPlugin
{
    private const EPSILON = 0.000001;

    /**
     * @param Tracer $tracer
     * @param CollectorSortOrders $sortOrders
     */
    public function __construct(
        private readonly Tracer $tracer,
        private readonly CollectorSortOrders $sortOrders
    ) {
    }

    /**
     * Snapshot total amounts around the collector.
     *
     * @param AbstractTotal $subject
     * @param callable $proceed
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return mixed
     */
    public function aroundCollect(
        AbstractTotal $subject,
        callable $proceed,
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ): mixed {
        if (!$this->tracer->isActive()) {
            return $proceed($quote, $shippingAssignment, $total);
        }

        $before = $this->amounts($total, false);
        $baseBefore = $this->amounts($total, true);
        $result = $proceed($quote, $shippingAssignment, $total);

        $class = $this->realClass($subject);
        $code = (string)$subject->getCode();
        $this->tracer->addCollectorStep(new CollectorStep(
            $code,
            $class,
            !str_starts_with($class, 'Magento\\'),
            (string)$shippingAssignment->getShipping()?->getAddress()?->getAddressType(),
            $this->delta($before, $this->amounts($total, false)),
            $this->delta($baseBefore, $this->amounts($total, true)),
            $this->sortOrders->get($code)
        ));

        return $result;
    }

    /**
     * Total amounts plus the grand total, which core keeps as plain data rather than a total amount.
     *
     * @param Total $total
     * @param bool $base
     * @return array
     */
    private function amounts(Total $total, bool $base): array
    {
        return $base
            ? $total->getAllBaseTotalAmounts() + ['grand_total' => $total->getData('base_grand_total')]
            : $total->getAllTotalAmounts() + ['grand_total' => $total->getData('grand_total')];
    }

    /**
     * Collector class without the generated Interceptor suffix.
     *
     * @param object $subject
     * @return string
     */
    private function realClass(object $subject): string
    {
        $class = get_class($subject);

        return str_ends_with($class, '\\Interceptor') ? substr($class, 0, -strlen('\\Interceptor')) : $class;
    }

    /**
     * Non-zero changes per total code.
     *
     * @param array $before
     * @param array $after
     * @return float[]
     */
    private function delta(array $before, array $after): array
    {
        $delta = [];
        foreach (array_keys($before + $after) as $code) {
            $change = (float)($after[$code] ?? 0) - (float)($before[$code] ?? 0);
            if (abs($change) > self::EPSILON) {
                $delta[$code] = $change;
            }
        }

        return $delta;
    }
}
