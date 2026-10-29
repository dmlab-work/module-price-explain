<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Model\Price\IndexChecker;
use DmLab\PriceExplain\Model\Price\ItemPriceExplainer;
use DmLab\PriceExplain\Model\Quote\IsolatedRecollector;
use DmLab\PriceExplain\Model\Quote\QuoteScopeResolver;
use DmLab\PriceExplain\Model\Quote\TotalsReader;
use DmLab\PriceExplain\Model\Report\Labels;
use DmLab\PriceExplain\Model\Report\OrderContext;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Report\Totals;
use DmLab\PriceExplain\Model\Report\Warning;
use DmLab\PriceExplain\Model\Rule\CandidateFilter;
use DmLab\PriceExplain\Model\Rule\RuleExplainer;
use DmLab\PriceExplain\Model\Shipping\FreeShippingExplainer;
use DmLab\PriceExplain\Model\Tax\TaxExplainer;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Recollects the quote once and assembles the report from the price, rule and tax explainers.
 */
class Explainer implements ExplainerInterface
{
    public const WARNING_ORDER_CURRENT = 'order_current_state';
    public const WARNING_ORDER_TOTALS = 'order_totals_mismatch';
    public const WARNING_ORDER_RULES = 'order_rules_mismatch';
    public const WARNING_MULTISHIPPING = 'multishipping';
    public const WARNING_AREA = 'area';

    /**
     * @var bool
     */
    private bool $used = false;

    /**
     * @param IsolatedRecollector $recollector
     * @param CandidateFilter $candidateFilter
     * @param RuleExplainer $ruleExplainer
     * @param ItemPriceExplainer $itemPriceExplainer
     * @param IndexChecker $indexChecker
     * @param TaxExplainer $taxExplainer
     * @param FreeShippingExplainer $freeShippingExplainer
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ScopeInterface $configScope
     * @param QuoteScopeResolver $scopeResolver
     * @param TotalsReader $totalsReader
     */
    public function __construct(
        private readonly IsolatedRecollector $recollector,
        private readonly CandidateFilter $candidateFilter,
        private readonly RuleExplainer $ruleExplainer,
        private readonly ItemPriceExplainer $itemPriceExplainer,
        private readonly IndexChecker $indexChecker,
        private readonly TaxExplainer $taxExplainer,
        private readonly FreeShippingExplainer $freeShippingExplainer,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ScopeInterface $configScope,
        private readonly QuoteScopeResolver $scopeResolver,
        private readonly TotalsReader $totalsReader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function explainQuote(int $quoteId, bool $includeAllRules = false): Report
    {
        return $this->explain($quoteId, $includeAllRules)['report'];
    }

    /**
     * @inheritDoc
     */
    public function explainOrder(string $incrementId, bool $includeAllRules = false): Report
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->create();
        $orders = array_values($this->orderRepository->getList($criteria)->getItems());
        if ($orders === []) {
            throw new NoSuchEntityException(__('Order "%1" does not exist.', $incrementId));
        }
        if (count($orders) > 1) {
            throw new LocalizedException(
                __(
                    'Order number "%1" exists in several stores; open the order and explain it from there.',
                    $incrementId
                )
            );
        }

        return $this->explainOrderEntity($orders[0], $includeAllRules);
    }

    /**
     * @inheritDoc
     */
    public function explainOrderById(int $orderId, bool $includeAllRules = false): Report
    {
        return $this->explainOrderEntity($this->orderRepository->get($orderId), $includeAllRules);
    }

    /**
     * Explain the quote of an order and compare the order with the recollected totals.
     *
     * @param OrderInterface $order
     * @param bool $includeAllRules
     * @return Report
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    private function explainOrderEntity(OrderInterface $order, bool $includeAllRules): Report
    {
        $incrementId = (string)$order->getIncrementId();
        $quoteId = (int)$order->getQuoteId();
        if ($quoteId === 0) {
            throw new NoSuchEntityException(__('Order "%1" has no quote to explain.', $incrementId));
        }
        try {
            ['report' => $report, 'quote' => $quote] = $this->explain($quoteId, $includeAllRules);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(
                __(
                    'The quote %1 of order "%2" no longer exists, so the order cannot be explained.',
                    $quoteId,
                    $incrementId
                ),
                $e
            );
        }

        $context = new OrderContext(
            $incrementId,
            (int)$order->getEntityId(),
            $order->getCreatedAt(),
            $order->getStatus()
        );

        $totals = $this->totalsReader->read($quote, $order);

        return $report->withOrder($context, $totals)->withWarnings(array_merge(
            [new Warning(
                self::WARNING_ORDER_CURRENT,
                (string)__(
                    'Order "%1" is evaluated with current rules and prices, not the ones valid when it was placed.',
                    $incrementId
                ),
                ['order' => $incrementId, 'created_at' => $order->getCreatedAt()]
            )],
            $this->compareOrder($order, $quote, $totals, $report->getPrecision())
        ));
    }

    /**
     * Recollect a quote once and build its report.
     *
     * @param int $quoteId
     * @param bool $includeAllRules
     * @return array{report: Report, quote: Quote}
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    private function explain(int $quoteId, bool $includeAllRules): array
    {
        // Core memoises rule validity per address and request: a second run would reuse it.
        if ($this->used) {
            throw new LocalizedException(__('Only one price explanation can run per request.'));
        }
        $this->used = true;

        $recollection = $this->recollector->recollect($quoteId);
        $quote = $recollection->getQuote();
        $couponCode = $recollection->getOriginalCouponCode();
        $websiteId = (int)$quote->getStore()->getWebsiteId();
        $groupId = (int)$quote->getCustomerGroupId();
        $precision = PriceCurrencyInterface::DEFAULT_PRECISION;

        $prices = $this->itemPriceExplainer->explain($quote);
        $rules = $this->ruleExplainer->explain(
            $quote,
            $this->candidateFilter->filter($quote, $couponCode, $includeAllRules)
        );
        $freeShipping = $this->freeShippingExplainer->explain($quote, $rules->getVerdicts());
        $tax = $this->taxExplainer->explain($quote);
        $indexWarnings = $this->indexChecker->check($websiteId, $groupId, $prices->getItems(), $precision);

        $report = new Report(
            (int)$quote->getId(),
            (int)$quote->getStoreId(),
            $websiteId,
            $groupId,
            $couponCode,
            (string)$quote->getQuoteCurrencyCode(),
            $precision,
            $prices->getItems(),
            $recollection->getCollectorSteps(),
            $freeShipping->getVerdicts(),
            $tax,
            array_merge(
                $prices->getWarnings(),
                $indexWarnings,
                $rules->getWarnings(),
                $freeShipping->getWarnings(),
                $this->multishipping($quote),
                $this->area()
            ),
            $freeShipping->getFreeShipping(),
            $this->scopeResolver->resolve($quote),
            null,
            $this->totalsReader->read($quote)
        );

        return ['report' => $report, 'quote' => $quote];
    }

    /**
     * Warning that rules and tax cover one shipping address of a multishipping quote.
     *
     * @param Quote $quote
     * @return Warning[]
     */
    private function multishipping(Quote $quote): array
    {
        if (!$quote->getIsMultiShipping()) {
            return [];
        }

        return [new Warning(
            self::WARNING_MULTISHIPPING,
            (string)__('Multishipping quote: cart rules and tax are explained for the first shipping address only.'),
            ['quote_id' => (int)$quote->getId()]
        )];
    }

    /**
     * Warning that totals were collected with another area's observers and plugins than the storefront's.
     *
     * Store emulation does not switch the config scope, so admin pages collect with adminhtml config.
     *
     * @return Warning[]
     */
    private function area(): array
    {
        $scope = (string)$this->configScope->getCurrentScope();
        if ($scope === Area::AREA_FRONTEND) {
            return [];
        }

        return [new Warning(
            self::WARNING_AREA,
            (string)__(
                'Totals were collected in the "%1" area: storefront-only observers and plugins did not run. '
                . 'Run dmlab:price:explain for the storefront result.',
                $scope
            ),
            ['area' => $scope]
        )];
    }

    /**
     * Totals and applied rules that differ between the order and the recollected quote.
     *
     * @param OrderInterface $order
     * @param Quote $quote
     * @param Totals $totals with the ordered amounts
     * @param int $precision
     * @return Warning[]
     */
    private function compareOrder(OrderInterface $order, Quote $quote, Totals $totals, int $precision): array
    {
        $amounts = $totals->toArray($precision);
        $diffs = [];
        foreach ($amounts['differences'] as $code) {
            $diffs[$code] = ['order' => $amounts['ordered'][$code], 'current' => $amounts['current'][$code]];
        }

        $warnings = [];
        if ($diffs !== []) {
            $warnings[] = new Warning(
                self::WARNING_ORDER_TOTALS,
                (string)__(
                    'Totals differ between the order and the current recollection: %1.',
                    implode(', ', array_map(Labels::total(...), array_keys($diffs)))
                ),
                $diffs
            );
        }

        $orderRules = $this->ruleIds((string)$order->getAppliedRuleIds());
        $currentRules = $this->ruleIds((string)$quote->getAppliedRuleIds());
        if ($orderRules !== $currentRules) {
            $warnings[] = new Warning(
                self::WARNING_ORDER_RULES,
                (string)__(
                    'Applied cart rules differ: order %1, current %2.',
                    $orderRules === [] ? '-' : implode(',', $orderRules),
                    $currentRules === [] ? '-' : implode(',', $currentRules)
                ),
                [
                    'order' => $orderRules,
                    'current' => $currentRules,
                    'only_order' => array_values(array_diff($orderRules, $currentRules)),
                    'only_current' => array_values(array_diff($currentRules, $orderRules)),
                ]
            );
        }

        return $warnings;
    }

    /**
     * Sorted unique rule IDs of an applied_rule_ids value.
     *
     * @param string $value
     * @return int[]
     */
    private function ruleIds(string $value): array
    {
        $ids = array_unique(array_map('intval', array_filter(explode(',', $value), 'strlen')));
        sort($ids);

        return array_values($ids);
    }
}
