<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Full explanation of one quote: item prices, totals collectors, cart price rules, free shipping, tax and warnings.
 */
class Report
{
    /**
     * @param int $quoteId
     * @param int $storeId
     * @param int $websiteId
     * @param int $customerGroupId
     * @param string|null $couponCode coupon as entered, before core may clear an invalid one
     * @param string $currency quote currency code
     * @param int $precision store price precision used for every amount
     * @param ItemPrice[] $items
     * @param CollectorStep[] $collectors in sales.xml order
     * @param RuleVerdict[] $rules in sort_order
     * @param TaxBreakdown|null $tax
     * @param Warning[] $warnings
     * @param FreeShipping|null $freeShipping null when nothing grants free shipping
     * @param QuoteScope|null $scope store/website/group names and customer
     * @param OrderContext|null $order the explained order; null for a quote report
     * @param Totals|null $totals
     */
    public function __construct(
        private readonly int $quoteId,
        private readonly int $storeId,
        private readonly int $websiteId,
        private readonly int $customerGroupId,
        private readonly ?string $couponCode,
        private readonly string $currency,
        private readonly int $precision = 2,
        private readonly array $items = [],
        private readonly array $collectors = [],
        private readonly array $rules = [],
        private readonly ?TaxBreakdown $tax = null,
        private readonly array $warnings = [],
        private readonly ?FreeShipping $freeShipping = null,
        private readonly ?QuoteScope $scope = null,
        private readonly ?OrderContext $order = null,
        private readonly ?Totals $totals = null
    ) {
    }

    /**
     * Quote ID.
     *
     * @return int
     */
    public function getQuoteId(): int
    {
        return $this->quoteId;
    }

    /**
     * Store ID.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * Website ID.
     *
     * @return int
     */
    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    /**
     * Customer group ID.
     *
     * @return int
     */
    public function getCustomerGroupId(): int
    {
        return $this->customerGroupId;
    }

    /**
     * Coupon code as entered.
     *
     * @return string|null
     */
    public function getCouponCode(): ?string
    {
        return $this->couponCode;
    }

    /**
     * Quote currency code.
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Amount precision.
     *
     * @return int
     */
    public function getPrecision(): int
    {
        return $this->precision;
    }

    /**
     * Item price explanations.
     *
     * @return ItemPrice[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Collector steps.
     *
     * @return CollectorStep[]
     */
    public function getCollectors(): array
    {
        return $this->collectors;
    }

    /**
     * Rule verdicts.
     *
     * @return RuleVerdict[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Free-shipping section.
     *
     * @return FreeShipping|null
     */
    public function getFreeShipping(): ?FreeShipping
    {
        return $this->freeShipping;
    }

    /**
     * Store/website/group names and customer.
     *
     * @return QuoteScope|null
     */
    public function getScope(): ?QuoteScope
    {
        return $this->scope;
    }

    /**
     * The explained order, null for a quote report.
     *
     * @return OrderContext|null
     */
    public function getOrder(): ?OrderContext
    {
        return $this->order;
    }

    /**
     * Subtotal, discount, shipping, tax and grand total.
     *
     * @return Totals|null
     */
    public function getTotals(): ?Totals
    {
        return $this->totals;
    }

    /**
     * Tax breakdown.
     *
     * @return TaxBreakdown|null
     */
    public function getTax(): ?TaxBreakdown
    {
        return $this->tax;
    }

    /**
     * Warnings.
     *
     * @return Warning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Copy with extra warnings appended.
     *
     * @param Warning[] $warnings
     * @return Report
     */
    public function withWarnings(array $warnings): Report
    {
        return $this->copy(array_merge($this->warnings, $warnings), $this->order, $this->totals);
    }

    /**
     * Copy that explains the given order, optionally with totals that include the ordered amounts.
     *
     * @param OrderContext $order
     * @param Totals|null $totals null keeps the current totals
     * @return Report
     */
    public function withOrder(OrderContext $order, ?Totals $totals = null): Report
    {
        return $this->copy($this->warnings, $order, $totals ?? $this->totals);
    }

    /**
     * Copy with the given warnings, order and totals.
     *
     * @param Warning[] $warnings
     * @param OrderContext|null $order
     * @param Totals|null $totals
     * @return Report
     */
    private function copy(array $warnings, ?OrderContext $order, ?Totals $totals): Report
    {
        return new self(
            $this->quoteId,
            $this->storeId,
            $this->websiteId,
            $this->customerGroupId,
            $this->couponCode,
            $this->currency,
            $this->precision,
            $this->items,
            $this->collectors,
            $this->rules,
            $this->tax,
            $warnings,
            $this->freeShipping,
            $this->scope,
            $order,
            $totals
        );
    }

    /**
     * Stable JSON-encodable structure; amounts are strings with the store precision.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $precision = $this->precision;

        return [
            'quote' => [
                'id' => $this->quoteId,
                'store' => $this->storeId,
                'store_name' => $this->scope?->getStoreName(),
                'website' => $this->websiteId,
                'website_name' => $this->scope?->getWebsiteName(),
                'group' => $this->customerGroupId,
                'group_name' => $this->scope?->getCustomerGroupName(),
                'customer' => $this->scope?->customerToArray(),
                'coupon' => $this->couponCode,
                'currency' => $this->currency,
            ],
            'order' => $this->order?->toArray(),
            'totals' => $this->totals?->toArray($precision),
            'items' => array_map(static fn (ItemPrice $i): array => $i->toArray($precision), $this->items),
            'collectors' => array_map(
                static fn (CollectorStep $c): array => $c->toArray($precision),
                $this->collectors
            ),
            'rules' => array_map(static fn (RuleVerdict $r): array => $r->toArray($precision), $this->rules),
            'free_shipping' => $this->freeShipping?->toArray($precision),
            'tax' => $this->tax?->toArray($precision),
            'warnings' => array_map(static fn (Warning $w): array => $w->toArray(), $this->warnings),
        ];
    }
}
