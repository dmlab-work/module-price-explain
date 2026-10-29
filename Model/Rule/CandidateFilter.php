<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use DmLab\PriceExplain\Model\Report\Stage;
use Magento\Customer\Model\ResourceModel\GroupExcludedWebsite;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\UsageFactory;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\CustomerFactory as RuleCustomerFactory;

/**
 * Reproduces the SQL pre-filter of cart price rules (Rule\Collection::setValidationFilter) and the coupon/usage
 * checks of Utility::canProcessRule in PHP, so every rule gets the first stage it fails at.
 */
class CandidateFilter
{
    /**
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param CouponFactory $couponFactory
     * @param UsageFactory $usageFactory
     * @param RuleCustomerFactory $ruleCustomerFactory
     * @param GroupExcludedWebsite $groupExcludedWebsite
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly RuleCollectionFactory $ruleCollectionFactory,
        private readonly CouponFactory $couponFactory,
        private readonly UsageFactory $usageFactory,
        private readonly RuleCustomerFactory $ruleCustomerFactory,
        private readonly GroupExcludedWebsite $groupExcludedWebsite,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Evaluate every cart price rule against the quote, ordered by sort_order, then rule_id.
     *
     * @param Quote $quote
     * @param string|null $couponCode coupon as entered; core may clear an invalid one while collecting
     * @param bool $includeAllRules also return rules of other websites
     * @return Candidate[]
     */
    public function filter(Quote $quote, ?string $couponCode, bool $includeAllRules = false): array
    {
        $websiteId = (int)$quote->getStore()->getWebsiteId();
        $groupId = (int)$quote->getCustomerGroupId();
        $customerId = (int)$quote->getCustomerId();
        $today = $this->timezone->scopeDate($quote->getStoreId())->format('Y-m-d');
        $excludedWebsites = array_map(
            'intval',
            $this->groupExcludedWebsite->loadCustomerGroupExcludedWebsites($groupId)
        );
        $couponCode = $couponCode === null || $couponCode === '' ? null : $couponCode;
        $coupon = null;

        $rules = array_values($this->ruleCollectionFactory->create()->getItems());
        usort(
            $rules,
            static fn (Rule $a, Rule $b): int => [(int)$a->getSortOrder(), (int)$a->getId()]
                <=> [(int)$b->getSortOrder(), (int)$b->getId()]
        );

        $candidates = [];
        foreach ($rules as $rule) {
            if (!in_array($websiteId, array_map('intval', (array)$rule->getWebsiteIds()), true)) {
                if ($includeAllRules) {
                    $candidates[] = new Candidate(
                        $rule,
                        Stage::WEBSITE,
                        (string)__('The rule is not assigned to website ID %1.', $websiteId)
                    );
                }
                continue;
            }
            $failure = $this->checkActive($rule)
                ?? $this->checkCustomerGroup($rule, $groupId, $websiteId, $excludedWebsites)
                ?? $this->checkDates($rule, $today);
            if ($failure === null && (int)$rule->getCouponType() !== Rule::COUPON_TYPE_NO_COUPON) {
                $coupon ??= $couponCode === null ? null : $this->couponFactory->create()->loadByCode($couponCode);
                $failure = $this->checkCoupon($rule, $couponCode, $coupon)
                    ?? $this->checkCouponUsage($coupon, $customerId);
            }
            $failure ??= $this->checkRuleUsage($rule, $customerId);
            $candidates[] = new Candidate($rule, $failure[0] ?? null, $failure[1] ?? null);
        }

        return $candidates;
    }

    /**
     * Inactive rules are dropped by the SQL filter.
     *
     * @param Rule $rule
     * @return array{0: string, 1: string}|null
     */
    private function checkActive(Rule $rule): ?array
    {
        return $rule->getIsActive() ? null : [Stage::INACTIVE, (string)__('The rule is inactive.')];
    }

    /**
     * Customer group must be assigned and not excluded from the website.
     *
     * @param Rule $rule
     * @param int $groupId
     * @param int $websiteId
     * @param int[] $excludedWebsites
     * @return array{0: string, 1: string}|null
     */
    private function checkCustomerGroup(Rule $rule, int $groupId, int $websiteId, array $excludedWebsites): ?array
    {
        if (!in_array($groupId, array_map('intval', (array)$rule->getCustomerGroupIds()), true)) {
            return [
                Stage::CUSTOMER_GROUP,
                (string)__('The rule is not assigned to customer group ID %1.', $groupId),
            ];
        }
        if (in_array($websiteId, $excludedWebsites, true)) {
            return [
                Stage::CUSTOMER_GROUP,
                (string)__('Customer group ID %1 is excluded from website ID %2.', $groupId, $websiteId),
            ];
        }
        return null;
    }

    /**
     * From/to dates are inclusive and compared with today in the store timezone.
     *
     * @param Rule $rule
     * @param string $today Y-m-d
     * @return array{0: string, 1: string}|null
     */
    private function checkDates(Rule $rule, string $today): ?array
    {
        $from = $rule->getFromDate() ? substr((string)$rule->getFromDate(), 0, 10) : null;
        $to = $rule->getToDate() ? substr((string)$rule->getToDate(), 0, 10) : null;
        if ($from !== null && $from > $today) {
            return [Stage::DATE, (string)__('The rule starts on %1; today is %2.', $from, $today)];
        }
        if ($to !== null && $to < $today) {
            return [Stage::DATE, (string)__('The rule ended on %1; today is %2.', $to, $today)];
        }
        return null;
    }

    /**
     * The quote coupon must belong to the rule and match its coupon type.
     *
     * @param Rule $rule
     * @param string|null $couponCode
     * @param Coupon|null $coupon
     * @return array{0: string, 1: string}|null
     */
    private function checkCoupon(Rule $rule, ?string $couponCode, ?Coupon $coupon): ?array
    {
        if ($couponCode === null) {
            return [Stage::COUPON, (string)__('The rule requires a coupon; the quote has none.')];
        }
        if ($coupon === null || !$coupon->getId()) {
            return [Stage::COUPON, (string)__('Coupon "%1" does not exist.', $couponCode)];
        }
        if ((int)$coupon->getRuleId() !== (int)$rule->getId()) {
            return [
                Stage::COUPON,
                (string)__('Coupon "%1" belongs to rule ID %2.', $couponCode, (int)$coupon->getRuleId()),
            ];
        }
        $generated = (int)$coupon->getType() === CouponInterface::TYPE_GENERATED;
        $expectGenerated = (int)$rule->getCouponType() === Rule::COUPON_TYPE_AUTO || $rule->getUseAutoGeneration();
        if ($generated !== $expectGenerated) {
            return [
                Stage::COUPON,
                (string)($expectGenerated
                    ? __('Coupon "%1" was created manually; the rule accepts only generated coupons.', $couponCode)
                    : __('Coupon "%1" was generated; the rule accepts only its specific coupon.', $couponCode)),
            ];
        }
        return null;
    }

    /**
     * Coupon usage_limit and usage_per_customer, as in ValidateCoupon; guests skip the per-customer limit.
     *
     * @param Coupon $coupon
     * @param int $customerId
     * @return array{0: string, 1: string}|null
     */
    private function checkCouponUsage(Coupon $coupon, int $customerId): ?array
    {
        $limit = (int)$coupon->getUsageLimit();
        $used = (int)$coupon->getTimesUsed();
        if ($limit && $used >= $limit) {
            return [
                Stage::COUPON_USAGE,
                (string)__('Coupon "%1" was used %2 of %3 times.', $coupon->getCode(), $used, $limit),
            ];
        }
        $perCustomer = (int)$coupon->getUsagePerCustomer();
        if (!$customerId || !$perCustomer) {
            return null;
        }
        $usage = new DataObject();
        $this->usageFactory->create()->loadByCustomerCoupon($usage, $customerId, $coupon->getId());
        $customerUsed = (int)$usage->getTimesUsed();
        if ($usage->getCouponId() && $customerUsed >= $perCustomer) {
            return [
                Stage::CUSTOMER_USAGE,
                (string)__(
                    'Coupon "%1" was used %2 of %3 times by this customer.',
                    $coupon->getCode(),
                    $customerUsed,
                    $perCustomer
                ),
            ];
        }
        return null;
    }

    /**
     * Rule uses_per_customer from salesrule_customer; guests skip it.
     *
     * @param Rule $rule
     * @param int $customerId
     * @return array{0: string, 1: string}|null
     */
    private function checkRuleUsage(Rule $rule, int $customerId): ?array
    {
        $limit = (int)$rule->getUsesPerCustomer();
        if (!$customerId || !$limit) {
            return null;
        }
        $ruleCustomer = $this->ruleCustomerFactory->create();
        $ruleCustomer->loadByCustomerRule($customerId, (int)$rule->getId());
        $used = (int)$ruleCustomer->getTimesUsed();
        if ($ruleCustomer->getId() && $used >= $limit) {
            return [
                Stage::CUSTOMER_USAGE,
                (string)__('The customer used the rule %1 of %2 times.', $used, $limit),
            ];
        }
        return null;
    }
}
