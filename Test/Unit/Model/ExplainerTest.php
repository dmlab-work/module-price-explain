<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model;

use DmLab\PriceExplain\Model\Explainer;
use DmLab\PriceExplain\Model\Price\IndexChecker;
use DmLab\PriceExplain\Model\Price\ItemPriceExplainer;
use DmLab\PriceExplain\Model\Price\PricesExplanation;
use DmLab\PriceExplain\Model\Quote\IsolatedRecollector;
use DmLab\PriceExplain\Model\Quote\QuoteScopeResolver;
use DmLab\PriceExplain\Model\Quote\TotalsReader;
use DmLab\PriceExplain\Model\Quote\Recollection;
use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\QuoteScope;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\TaxBreakdown;
use DmLab\PriceExplain\Model\Report\Warning;
use DmLab\PriceExplain\Model\Rule\Candidate;
use DmLab\PriceExplain\Model\Rule\CandidateFilter;
use DmLab\PriceExplain\Model\Rule\RuleExplainer;
use DmLab\PriceExplain\Model\Rule\RulesExplanation;
use DmLab\PriceExplain\Model\Shipping\FreeShippingExplainer;
use DmLab\PriceExplain\Model\Shipping\FreeShippingExplanation;
use DmLab\PriceExplain\Model\Tax\TaxExplainer;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\SalesRule\Model\Rule;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ExplainerTest extends TestCase
{
    /** @var string[] */
    private array $log = [];
    /** @var IsolatedRecollector&MockObject */
    private MockObject $recollector;
    /** @var CandidateFilter&MockObject */
    private MockObject $candidateFilter;
    /** @var ItemPriceExplainer&MockObject */
    private MockObject $itemPriceExplainer;
    /** @var OrderRepositoryInterface&MockObject */
    private MockObject $orderRepository;
    /** @var OrderInterface[] */
    private array $orders = [];
    /** @var Quote&MockObject */
    private MockObject $quote;
    /** @var DataObject[] */
    private array $addresses = [];
    /** @var string */
    private string $area = 'frontend';

    protected function setUp(): void
    {
        $this->addresses = [
            new DataObject(['discount_amount' => 0.0, 'shipping_amount' => 0.0, 'tax_amount' => 0.0]),
            new DataObject(['discount_amount' => -10.0, 'shipping_amount' => 5.0, 'tax_amount' => 8.0]),
        ];
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(2);
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStore', 'getAllAddresses'])
            ->getMock();
        $this->quote->method('getId')->willReturn(42);
        $this->quote->method('getStore')->willReturn($store);
        $this->quote->method('getAllAddresses')->willReturnCallback(fn (): array => $this->addresses);
        $this->quote->setData([
            'store_id' => 3,
            'customer_group_id' => 1,
            'quote_currency_code' => 'EUR',
            'subtotal' => 100.0,
            'grand_total' => 103.0,
            'applied_rule_ids' => '2,1',
        ]);

        $this->recollector = $this->createMock(IsolatedRecollector::class);
        $this->recollector->method('recollect')->willReturnCallback(function (int $quoteId): Recollection {
            $this->log[] = 'recollect:' . $quoteId;
            $step = new CollectorStep('subtotal', 'Magento\\Quote\\Total\\Subtotal', false, 'shipping', []);
            return new Recollection($this->quote, [$step], 'SPRING');
        });
        $this->candidateFilter = $this->createMock(CandidateFilter::class);
        $this->candidateFilter->method('filter')->willReturnCallback(
            function (Quote $quote, ?string $coupon, bool $all): array {
                $this->log[] = 'filter:' . $coupon . ':' . ($all ? 'all' : 'website');
                return [new Candidate($this->createMock(Rule::class))];
            }
        );
        $this->itemPriceExplainer = $this->createMock(ItemPriceExplainer::class);
        $this->itemPriceExplainer->method('explain')->willReturnCallback(function (): PricesExplanation {
            $this->log[] = 'prices';
            return new PricesExplanation([$this->itemPrice()], [new Warning('price_delta', 'p')]);
        });
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('getList')->willReturnCallback(function (): OrderSearchResultInterface {
            $result = $this->createMock(OrderSearchResultInterface::class);
            $result->method('getItems')->willReturn($this->orders);
            return $result;
        });
    }

    public function testExplainQuoteOrchestratesOneRecollectionAndAssemblesReport(): void
    {
        $report = $this->explainer()->explainQuote(42, true);

        self::assertSame(
            ['recollect:42', 'prices', 'filter:SPRING:all', 'rules:1', 'free_shipping:1', 'tax', 'index:2:1:1'],
            $this->log
        );
        self::assertSame(42, $report->getQuoteId());
        self::assertSame(3, $report->getStoreId());
        self::assertSame(2, $report->getWebsiteId());
        self::assertSame(1, $report->getCustomerGroupId());
        self::assertSame('SPRING', $report->getCouponCode());
        self::assertSame('Main Website', $report->getScope()->getWebsiteName());
        self::assertSame('EUR', $report->getCurrency());
        self::assertSame(2, $report->getPrecision());
        self::assertCount(1, $report->getItems());
        self::assertSame('subtotal', $report->getCollectors()[0]->getCode());
        self::assertSame(7, $report->getRules()[0]->getRuleId());
        self::assertSame(FreeShipping::NOT_HONOURED, $report->getRules()[0]->getFreeShippingOutcome());
        self::assertSame(FreeShipping::NOT_HONOURED, $report->getFreeShipping()->getVerdict());
        self::assertSame('shipping', $report->getTax()->getBasedOn());
        self::assertSame(
            ['price_delta', 'index_mismatch', 'rule_mismatch', 'free_shipping_not_effective'],
            array_map(static fn (Warning $w): string => $w->getCode(), $report->getWarnings())
        );
        self::assertNull($report->getOrder());
        self::assertSame(
            ['subtotal' => 100.0, 'discount' => -10.0, 'shipping' => 5.0, 'tax' => 8.0, 'grand_total' => 103.0],
            $report->getTotals()->getCurrent()
        );
        self::assertNull($report->getTotals()->getOrdered());
        self::assertNotFalse(json_encode($report->toArray()));
    }

    public function testMultishippingQuoteIsFlagged(): void
    {
        $this->quote->setData('is_multi_shipping', 1);

        $warnings = $this->explainer()->explainQuote(42)->getWarnings();
        $warning = $this->warning($warnings, Explainer::WARNING_MULTISHIPPING);

        self::assertNotNull($warning);
        self::assertSame(['quote_id' => 42], $warning->getContext());
    }

    public function testFrontendAreaIsNotFlagged(): void
    {
        $warnings = $this->explainer()->explainQuote(42)->getWarnings();

        self::assertNull($this->warning($warnings, Explainer::WARNING_AREA));
    }

    public function testNonFrontendAreaIsFlagged(): void
    {
        $this->area = 'adminhtml';

        $warnings = $this->explainer()->explainQuote(42)->getWarnings();
        $warning = $this->warning($warnings, Explainer::WARNING_AREA);

        self::assertNotNull($warning);
        self::assertSame(['area' => 'adminhtml'], $warning->getContext());
    }

    public function testDefaultsToCurrentWebsiteRules(): void
    {
        $this->explainer()->explainQuote(42);

        self::assertContains('filter:SPRING:website', $this->log);
    }

    public function testSecondExplanationInSameRequestIsRefused(): void
    {
        $explainer = $this->explainer();
        $explainer->explainQuote(42);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Only one price explanation can run per request.');
        $explainer->explainQuote(42);
    }

    public function testMissingQuotePropagates(): void
    {
        $this->recollector = $this->createMock(IsolatedRecollector::class);
        $this->recollector->method('recollect')
            ->willThrowException(new NoSuchEntityException(__('Quote with ID "%1" does not exist.', 9)));

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Quote with ID "9" does not exist.');
        $this->explainer()->explainQuote(9);
    }

    public function testOrderMatchingRecollectionOnlyWarnsAboutCurrentState(): void
    {
        $this->orders = [$this->order()];

        $report = $this->explainer()->explainOrder('000000100');

        self::assertContains('recollect:42', $this->log);
        $warning = $this->warning($report->getWarnings(), Explainer::WARNING_ORDER_CURRENT);
        self::assertStringContainsString('"000000100"', $warning->getMessage());
        self::assertSame(
            ['order' => '000000100', 'created_at' => '2026-10-01 10:00:00'],
            $warning->getContext()
        );
        self::assertNull($this->warning($report->getWarnings(), Explainer::WARNING_ORDER_TOTALS));
        self::assertNull($this->warning($report->getWarnings(), Explainer::WARNING_ORDER_RULES));
    }

    public function testOrderReportCarriesOrderContext(): void
    {
        $this->orders = [$this->order()];

        $report = $this->explainer()->explainOrder('000000100');

        self::assertSame(
            [
                'increment_id' => '000000100',
                'entity_id' => 9,
                'created_at' => '2026-10-01 10:00:00',
                'status' => 'processing',
            ],
            $report->toArray()['order']
        );
        self::assertSame(42, $report->getQuoteId());
    }

    public function testOrderComparisonReportsDifferingTotalsAndRules(): void
    {
        $this->orders = [$this->order([
            'subtotal' => 100.0,
            'discount_amount' => -15.0,
            'shipping_amount' => 5.0,
            'tax_amount' => 8.004,
            'grand_total' => 98.0,
            'applied_rule_ids' => '1,3',
        ])];

        $report = $this->explainer()->explainOrder('000000100');

        $totals = $this->warning($report->getWarnings(), Explainer::WARNING_ORDER_TOTALS);
        self::assertSame(
            [
                'discount' => ['order' => '-15.00', 'current' => '-10.00'],
                'grand_total' => ['order' => '98.00', 'current' => '103.00'],
            ],
            $totals->getContext()
        );
        self::assertStringContainsString('Discount, Grand Total', $totals->getMessage());
        self::assertSame(['discount', 'grand_total'], $report->toArray()['totals']['differences']);
        self::assertSame('-15.00', $report->toArray()['totals']['ordered']['discount']);
        self::assertSame('-10.00', $report->toArray()['totals']['current']['discount']);

        $rules = $this->warning($report->getWarnings(), Explainer::WARNING_ORDER_RULES);
        self::assertSame(
            ['order' => [1, 3], 'current' => [1, 2], 'only_order' => [3], 'only_current' => [2]],
            $rules->getContext()
        );
        self::assertSame('Applied cart rules differ: order 1,3, current 1,2.', $rules->getMessage());
    }

    public function testOrderWithoutRulesAgainstRecollectionWithRules(): void
    {
        $this->orders = [$this->order(['applied_rule_ids' => null])];

        $report = $this->explainer()->explainOrder('000000100');

        $rules = $this->warning($report->getWarnings(), Explainer::WARNING_ORDER_RULES);
        self::assertSame('Applied cart rules differ: order -, current 1,2.', $rules->getMessage());
    }

    public function testExplainOrderByIdUsesRepository(): void
    {
        $this->orderRepository->expects(self::once())->method('get')->with(5)->willReturn($this->order());

        $report = $this->explainer()->explainOrderById(5);

        self::assertNotNull($this->warning($report->getWarnings(), Explainer::WARNING_ORDER_CURRENT));
    }

    public function testMissingOrder(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Order "404" does not exist.');
        $this->explainer()->explainOrder('404');
    }

    public function testAmbiguousOrderNumber(): void
    {
        $this->orders = [$this->order(), $this->order()];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('exists in several stores');
        $this->explainer()->explainOrder('000000100');
    }

    public function testOrderWithoutQuote(): void
    {
        $this->orders = [$this->order(['quote_id' => null])];

        try {
            $this->explainer()->explainOrder('000000100');
            self::fail('Exception expected');
        } catch (NoSuchEntityException $e) {
            self::assertSame('Order "000000100" has no quote to explain.', $e->getMessage());
        }
        self::assertSame([], $this->log);
    }

    public function testOrderWhoseQuoteWasDeleted(): void
    {
        $this->orders = [$this->order()];
        $this->recollector = $this->createMock(IsolatedRecollector::class);
        $this->recollector->method('recollect')
            ->willThrowException(new NoSuchEntityException(__('Quote with ID "%1" does not exist.', 42)));

        try {
            $this->explainer()->explainOrder('000000100');
            self::fail('Exception expected');
        } catch (NoSuchEntityException $e) {
            self::assertSame(
                'The quote 42 of order "000000100" no longer exists, so the order cannot be explained.',
                $e->getMessage()
            );
            self::assertInstanceOf(NoSuchEntityException::class, $e->getPrevious());
        }
    }

    private function explainer(): Explainer
    {
        $ruleExplainer = $this->createMock(RuleExplainer::class);
        $ruleExplainer->method('explain')->willReturnCallback(function (Quote $quote, array $candidates) {
            $this->log[] = 'rules:' . count($candidates);
            return new RulesExplanation(
                [new RuleVerdict(7, 'Spring', 1, Status::APPLIED)],
                [new Warning('rule_mismatch', 'r')]
            );
        });
        $taxExplainer = $this->createMock(TaxExplainer::class);
        $taxExplainer->method('explain')->willReturnCallback(function (): TaxBreakdown {
            $this->log[] = 'tax';
            return new TaxBreakdown('shipping', 'TOTAL_BASE_CALCULATION');
        });
        $freeShippingExplainer = $this->createMock(FreeShippingExplainer::class);
        $freeShippingExplainer->method('explain')->willReturnCallback(
            function (Quote $quote, array $verdicts): FreeShippingExplanation {
                $this->log[] = 'free_shipping:' . count($verdicts);
                return new FreeShippingExplanation(
                    new FreeShipping(FreeShipping::NOT_HONOURED, 'f'),
                    [$verdicts[0]->withFreeShippingOutcome(FreeShipping::NOT_HONOURED)],
                    [new Warning('free_shipping_not_effective', 'f')]
                );
            }
        );
        $indexChecker = $this->createMock(IndexChecker::class);
        $indexChecker->method('check')->willReturnCallback(
            function (int $websiteId, int $groupId, array $items, int $precision): array {
                $this->log[] = sprintf('index:%d:%d:%d', $websiteId, $groupId, count($items));
                self::assertSame(2, $precision);
                return [new Warning('index_mismatch', 'i')];
            }
        );
        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->with('increment_id')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn(new SearchCriteria());
        $configScope = $this->createStub(ScopeInterface::class);
        $configScope->method('getCurrentScope')->willReturnCallback(fn (): string => $this->area);
        $scopeResolver = $this->createMock(QuoteScopeResolver::class);
        $scopeResolver->method('resolve')->with($this->quote)
            ->willReturn(new QuoteScope('Default Store View', 'Main Website', 'General', 5));

        return new Explainer(
            $this->recollector,
            $this->candidateFilter,
            $ruleExplainer,
            $this->itemPriceExplainer,
            $indexChecker,
            $taxExplainer,
            $freeShippingExplainer,
            $this->orderRepository,
            $criteriaBuilder,
            $configScope,
            $scopeResolver,
            new TotalsReader()
        );
    }

    /**
     * @param array<string, mixed> $data overrides of a matching order
     * @return OrderInterface
     */
    private function order(array $data = []): OrderInterface
    {
        $data += [
            'increment_id' => '000000100',
            'entity_id' => 9,
            'status' => 'processing',
            'quote_id' => 42,
            'created_at' => '2026-10-01 10:00:00',
            'subtotal' => 100.0,
            'discount_amount' => -10.0,
            'shipping_amount' => 5.0,
            'tax_amount' => 8.0,
            'grand_total' => 103.0,
            'applied_rule_ids' => '1,2',
        ];
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($data['increment_id']);
        $order->method('getEntityId')->willReturn($data['entity_id']);
        $order->method('getStatus')->willReturn($data['status']);
        $order->method('getQuoteId')->willReturn($data['quote_id']);
        $order->method('getCreatedAt')->willReturn($data['created_at']);
        $order->method('getSubtotal')->willReturn($data['subtotal']);
        $order->method('getDiscountAmount')->willReturn($data['discount_amount']);
        $order->method('getShippingAmount')->willReturn($data['shipping_amount']);
        $order->method('getTaxAmount')->willReturn($data['tax_amount']);
        $order->method('getGrandTotal')->willReturn($data['grand_total']);
        $order->method('getAppliedRuleIds')->willReturn($data['applied_rule_ids']);

        return $order;
    }

    /**
     * @param Warning[] $warnings
     * @param string $code
     * @return Warning|null
     */
    private function warning(array $warnings, string $code): ?Warning
    {
        foreach ($warnings as $warning) {
            if ($warning->getCode() === $code) {
                return $warning;
            }
        }

        return null;
    }

    private function itemPrice(): ItemPrice
    {
        return new ItemPrice(11, 'MH01', 'Item', 1.0, 5, [], 10.0, 10.0);
    }
}
