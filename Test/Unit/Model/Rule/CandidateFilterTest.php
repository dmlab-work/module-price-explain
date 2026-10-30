<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Rule;

use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Rule\Candidate;
use DmLab\PriceExplain\Model\Rule\CandidateFilter;
use Magento\Customer\Model\ResourceModel\GroupExcludedWebsite;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\Usage;
use Magento\SalesRule\Model\ResourceModel\Coupon\UsageFactory;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Customer as RuleCustomer;
use Magento\SalesRule\Model\Rule\CustomerFactory as RuleCustomerFactory;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CandidateFilterTest extends TestCase
{
    private const WEBSITE = 1;
    private const GROUP = 1;
    private const STORE = 3;

    /** @var Rule[] */
    private array $rules = [];
    /** @var array<string, array<string, mixed>> coupon data by code */
    private array $coupons = [];
    /** @var int[] */
    private array $excludedWebsites = [];
    /** @var array<int, int> coupon id => times used by the customer */
    private array $couponUsage = [];
    /** @var array<int, int> rule id => times used by the customer */
    private array $ruleUsage = [];
    /** @var string store-local "now" */
    private string $now = '2026-10-28 12:00:00';
    /** @var string */
    private string $timezoneName = 'UTC';
    /** @var int */
    private int $couponLoads = 0;
    /** @var int per-customer usage lookups */
    private int $usageLoads = 0;

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string|null}>
     */
    public static function stageProvider(): array
    {
        $specific = ['coupon_type' => Rule::COUPON_TYPE_SPECIFIC];
        $auto = ['coupon_type' => Rule::COUPON_TYPE_AUTO];
        $manual = ['coupon_id' => 5, 'rule_id' => 7, 'type' => 0, 'code' => 'SPRING'];
        $generated = ['coupon_id' => 5, 'rule_id' => 7, 'type' => 1, 'code' => 'SPRING'];

        return [
            'passes' => [[], [], null],
            'inactive' => [['is_active' => 0], [], Stage::INACTIVE],
            'group not assigned' => [['customer_group_ids' => [0, 2]], [], Stage::CUSTOMER_GROUP],
            'group excluded from website' => [[], ['excluded' => [self::WEBSITE]], Stage::CUSTOMER_GROUP],
            'group excluded from another website' => [[], ['excluded' => [2]], null],
            'from date today' => [['from_date' => '2026-10-28'], [], null],
            'from date tomorrow' => [['from_date' => '2026-10-29'], [], Stage::DATE],
            'to date today' => [['to_date' => '2026-10-28'], [], null],
            'to date yesterday' => [['to_date' => '2026-10-27'], [], Stage::DATE],
            'no coupon rule ignores quote coupon' => [[], ['coupon' => 'SPRING'], null],
            'coupon required, none given' => [$specific, [], Stage::COUPON],
            'coupon does not exist' => [$specific, ['coupon' => 'NOPE'], Stage::COUPON],
            'coupon of another rule' => [
                $specific,
                ['coupon' => 'SPRING', 'coupons' => [['rule_id' => 9] + $manual]],
                Stage::COUPON,
            ],
            'specific coupon' => [$specific, ['coupon' => 'SPRING', 'coupons' => [$manual]], null],
            'specific rule, generated coupon' => [
                $specific,
                ['coupon' => 'SPRING', 'coupons' => [$generated]],
                Stage::COUPON,
            ],
            'specific auto-generation rule, generated coupon' => [
                $specific + ['use_auto_generation' => 1],
                ['coupon' => 'SPRING', 'coupons' => [$generated]],
                null,
            ],
            'specific auto-generation rule, manual coupon' => [
                $specific + ['use_auto_generation' => 1],
                ['coupon' => 'SPRING', 'coupons' => [$manual]],
                Stage::COUPON,
            ],
            'auto rule, generated coupon' => [$auto, ['coupon' => 'SPRING', 'coupons' => [$generated]], null],
            'auto rule, manual coupon' => [$auto, ['coupon' => 'SPRING', 'coupons' => [$manual]], Stage::COUPON],
            'coupon usage limit reached' => [
                $specific,
                ['coupon' => 'SPRING', 'coupons' => [['usage_limit' => 3, 'times_used' => 3] + $manual]],
                Stage::COUPON_USAGE,
            ],
            'coupon usage below limit' => [
                $specific,
                ['coupon' => 'SPRING', 'coupons' => [['usage_limit' => 3, 'times_used' => 2] + $manual]],
                null,
            ],
            'coupon per-customer limit reached' => [
                $specific,
                [
                    'coupon' => 'SPRING',
                    'coupons' => [['usage_per_customer' => 1] + $manual],
                    'customer' => 10,
                    'coupon_usage' => [5 => 1],
                ],
                Stage::CUSTOMER_USAGE,
            ],
            'coupon per-customer never used' => [
                $specific,
                ['coupon' => 'SPRING', 'coupons' => [['usage_per_customer' => 1] + $manual], 'customer' => 10],
                null,
            ],
            'coupon per-customer, guest' => [
                $specific,
                [
                    'coupon' => 'SPRING',
                    'coupons' => [['usage_per_customer' => 1] + $manual],
                    'coupon_usage' => [5 => 9],
                ],
                null,
            ],
            'rule uses per customer reached' => [
                ['uses_per_customer' => 2],
                ['customer' => 10, 'rule_usage' => [7 => 2]],
                Stage::CUSTOMER_USAGE,
            ],
            'rule uses per customer below limit' => [
                ['uses_per_customer' => 2],
                ['customer' => 10, 'rule_usage' => [7 => 1]],
                null,
            ],
            'rule uses per customer, guest' => [['uses_per_customer' => 1], ['rule_usage' => [7 => 5]], null],
        ];
    }

    /**
     * @param array<string, mixed> $ruleData
     * @param array<string, mixed> $context
     * @param string|null $expectedStage
     */
    #[DataProvider('stageProvider')]
    public function testStage(array $ruleData, array $context, ?string $expectedStage): void
    {
        $this->rules = [$this->rule(7, $ruleData)];
        foreach ($context['coupons'] ?? [] as $coupon) {
            $this->coupons[$coupon['code']] = $coupon;
        }
        $this->excludedWebsites = $context['excluded'] ?? [];
        $this->couponUsage = $context['coupon_usage'] ?? [];
        $this->ruleUsage = $context['rule_usage'] ?? [];

        $candidates = $this->filter()->filter($this->quote($context['customer'] ?? null), $context['coupon'] ?? null);

        self::assertCount(1, $candidates);
        self::assertSame($expectedStage, $candidates[0]->getStage());
        self::assertSame($expectedStage === null, $candidates[0]->isCandidate());
        self::assertSame($expectedStage === null, $candidates[0]->getReason() === null);
        if (empty($context['customer'])) {
            self::assertSame(0, $this->usageLoads, 'guests skip per-customer checks');
        }
    }

    public function testOtherWebsiteRulesAreHiddenUnlessAllRulesRequested(): void
    {
        $this->rules = [$this->rule(7, ['website_ids' => [2]]), $this->rule(8, [])];

        $default = $this->filter()->filter($this->quote(null), null);
        self::assertSame([8], $this->ids($default));

        $all = $this->filter()->filter($this->quote(null), null, true);
        self::assertSame([7, 8], $this->ids($all));
        self::assertSame(Stage::WEBSITE, $all[0]->getStage());
        self::assertTrue($all[0]->isOtherWebsite());
        self::assertFalse($all[1]->isOtherWebsite());
        self::assertStringContainsString('website ID 1', (string)$all[0]->getReason());
    }

    public function testOrdersBySortOrderThenRuleId(): void
    {
        $this->rules = [
            $this->rule(9, ['sort_order' => 1]),
            $this->rule(4, ['sort_order' => 5]),
            $this->rule(3, ['sort_order' => 1]),
            $this->rule(1, ['sort_order' => 0]),
        ];

        self::assertSame([1, 3, 9, 4], $this->ids($this->filter()->filter($this->quote(null), null)));
    }

    public function testToDateUsesStoreTimezone(): void
    {
        // 23:30 on the 28th in Los Angeles is already the 29th in UTC.
        $this->now = '2026-10-28 23:30:00';
        $this->timezoneName = 'America/Los_Angeles';
        $this->rules = [
            $this->rule(7, ['to_date' => '2026-10-28']),
            $this->rule(8, ['from_date' => '2026-10-29 00:00:00']),
        ];

        $candidates = $this->filter()->filter($this->quote(null), null);

        self::assertNull($candidates[0]->getStage());
        self::assertSame(Stage::DATE, $candidates[1]->getStage());
        self::assertStringContainsString('2026-10-29', (string)$candidates[1]->getReason());
    }

    public function testCouponIsLoadedOnceAndOnlyForCouponRules(): void
    {
        $this->coupons['SPRING'] = ['coupon_id' => 5, 'rule_id' => 8, 'type' => 0, 'code' => 'SPRING'];
        $this->rules = [
            $this->rule(7, []),
            $this->rule(8, ['coupon_type' => Rule::COUPON_TYPE_SPECIFIC]),
            $this->rule(9, ['coupon_type' => Rule::COUPON_TYPE_SPECIFIC]),
        ];

        $candidates = $this->filter()->filter($this->quote(null), 'SPRING');

        self::assertSame(1, $this->couponLoads);
        self::assertNull($candidates[0]->getStage());
        self::assertNull($candidates[1]->getStage());
        self::assertSame(Stage::COUPON, $candidates[2]->getStage());
        self::assertStringContainsString('rule ID 8', (string)$candidates[2]->getReason());
    }

    public function testFailedPreFilterSkipsCouponLookup(): void
    {
        $this->rules = [$this->rule(7, ['is_active' => 0, 'coupon_type' => Rule::COUPON_TYPE_SPECIFIC])];

        $candidates = $this->filter()->filter($this->quote(null), 'SPRING');

        self::assertSame(Stage::INACTIVE, $candidates[0]->getStage());
        self::assertSame(0, $this->couponLoads);
    }

    public function testEmptyCouponCodeCountsAsNone(): void
    {
        $this->rules = [$this->rule(7, ['coupon_type' => Rule::COUPON_TYPE_SPECIFIC])];

        $candidates = $this->filter()->filter($this->quote(null), '');

        self::assertSame(Stage::COUPON, $candidates[0]->getStage());
        self::assertStringContainsString('has none', (string)$candidates[0]->getReason());
        self::assertSame(0, $this->couponLoads);
    }

    /**
     * @param Candidate[] $candidates
     * @return int[]
     */
    private function ids(array $candidates): array
    {
        return array_map(static fn (Candidate $c): int => (int)$c->getRule()->getId(), $candidates);
    }

    /**
     * @param int $id
     * @param array<string, mixed> $data
     * @return Rule&Stub
     */
    private function rule(int $id, array $data): Stub
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $rule->method('getId')->willReturn($id);
        $rule->setData($data + [
            'rule_id' => $id,
            'is_active' => 1,
            'website_ids' => [self::WEBSITE],
            'customer_group_ids' => [self::GROUP],
            'coupon_type' => Rule::COUPON_TYPE_NO_COUPON,
            'sort_order' => 0,
        ]);
        return $rule;
    }

    /**
     * @param int|null $customerId
     * @return Quote&Stub
     */
    private function quote(?int $customerId): Stub
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWebsiteId'])
            ->getMock();
        $store->method('getWebsiteId')->willReturn(self::WEBSITE);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStore', 'getStoreId'])
            ->getMock();
        $quote->method('getStore')->willReturn($store);
        $quote->method('getStoreId')->willReturn(self::STORE);
        $quote->setData(['customer_group_id' => self::GROUP, 'customer_id' => $customerId]);
        return $quote;
    }

    private function filter(): CandidateFilter
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturnCallback(fn (): array => $this->rules);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $couponFactory = $this->createStub(CouponFactory::class);
        $couponFactory->method('create')->willReturnCallback(fn (): Coupon => $this->coupon());

        $usage = $this->createStub(Usage::class);
        $usage->method('loadByCustomerCoupon')->willReturnCallback(
            function (DataObject $object, $customerId, $couponId) use ($usage) {
                $this->usageLoads++;
                self::assertSame(10, $customerId);
                if (isset($this->couponUsage[$couponId])) {
                    $object->setData(['coupon_id' => $couponId, 'times_used' => $this->couponUsage[$couponId]]);
                }
                return $usage;
            }
        );
        $usageFactory = $this->createStub(UsageFactory::class);
        $usageFactory->method('create')->willReturn($usage);

        $ruleCustomerFactory = $this->createStub(RuleCustomerFactory::class);
        $ruleCustomerFactory->method('create')->willReturnCallback(fn (): RuleCustomer => $this->ruleCustomer());

        $excluded = $this->createStub(GroupExcludedWebsite::class);
        $excluded->method('loadCustomerGroupExcludedWebsites')->willReturnCallback(function (int $groupId): array {
            self::assertSame(self::GROUP, $groupId);
            return array_map('strval', $this->excludedWebsites);
        });

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('scopeDate')->willReturnCallback(function ($scope): \DateTime {
            self::assertSame(self::STORE, $scope);
            return new \DateTime($this->now, new \DateTimeZone($this->timezoneName));
        });

        return new CandidateFilter(
            $collectionFactory,
            $couponFactory,
            $usageFactory,
            $ruleCustomerFactory,
            $excluded,
            $timezone
        );
    }

    /**
     * @return Coupon&Stub
     */
    private function coupon(): Stub
    {
        $coupon = $this->getMockBuilder(Coupon::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByCode', 'getId'])
            ->getMock();
        $coupon->method('loadByCode')->willReturnCallback(function (string $code) use ($coupon) {
            $this->couponLoads++;
            $coupon->setData($this->coupons[$code] ?? []);
            return $coupon;
        });
        $coupon->method('getId')->willReturnCallback(fn () => $coupon->getData('coupon_id'));
        return $coupon;
    }

    /**
     * @return RuleCustomer&Stub
     */
    private function ruleCustomer(): Stub
    {
        $ruleCustomer = $this->getMockBuilder(RuleCustomer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByCustomerRule', 'getId'])
            ->getMock();
        $ruleCustomer->method('loadByCustomerRule')->willReturnCallback(
            function ($customerId, $ruleId) use ($ruleCustomer) {
                $this->usageLoads++;
                self::assertSame(10, $customerId);
                if (isset($this->ruleUsage[$ruleId])) {
                    $ruleCustomer->setData(['rule_customer_id' => 1, 'times_used' => $this->ruleUsage[$ruleId]]);
                }
                return $ruleCustomer;
            }
        );
        $ruleCustomer->method('getId')->willReturnCallback(fn () => $ruleCustomer->getData('rule_customer_id'));
        return $ruleCustomer;
    }
}
