<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\RuleItemOutcome;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Stage;
use DmLab\PriceExplain\Model\Report\Status;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Api\Data\DiscountAppliedToInterface;
use Magento\SalesRule\Model\Quote\ChildrenValidationLocator;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;

/**
 * Reproduces Discount::collect gating per rule and item, takes amounts from the recollected quote
 * and reconciles the result with the rules core actually applied.
 */
class RuleExplainer
{
    public const WARNING_CODE = 'rule_mismatch';

    /**
     * @param ConditionWalker $walker
     * @param Validator $validator
     * @param ChildrenValidationLocator $childrenValidationLocator
     * @param ItemLabel $itemLabel
     * @param CategoryLabels $categoryLabels
     */
    public function __construct(
        private readonly ConditionWalker $walker,
        private readonly Validator $validator,
        private readonly ChildrenValidationLocator $childrenValidationLocator,
        private readonly ItemLabel $itemLabel,
        private readonly CategoryLabels $categoryLabels
    ) {
    }

    /**
     * Explain every rule of the pre-filter result (in processing order) for the recollected quote.
     *
     * @param Quote $quote recollected quote
     * @param Candidate[] $candidates ordered pre-filter result
     * @return RulesExplanation
     */
    public function explain(Quote $quote, array $candidates): RulesExplanation
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        // Children are folded under their parent, as Discount::collect skips them.
        $items = array_values(array_filter(
            $address->getAllItems(),
            static fn (AbstractItem $item): bool => !$item->getParentItem()
        ));
        $shippingAmounts = $this->shippingDiscounts($address);
        $itemDiscounts = $this->itemDiscountsByRule($items);
        $freeShipping = $quote->isVirtual()
            ? []
            : $this->freeShippingModes($address, $items, $candidates, $itemDiscounts);
        $earlierBase = $earlier = 0.0;
        $stoppedBy = [];
        $verdicts = [];
        $warnings = [];

        foreach ($candidates as $candidate) {
            $rule = $candidate->getRule();
            if (!$candidate->isCandidate()) {
                $verdicts[] = $this->verdict(
                    $rule,
                    $candidate->isOtherWebsite() ? Status::NOT_CANDIDATE : Status::REJECTED,
                    $candidate->getStage(),
                    $candidate->getReason()
                );
                continue;
            }

            // Validator::initTotals validates cart_fixed rules before the rules loop and memoises the result
            // for a saved address, so they see the undiscounted subtotal.
            $conditions = $rule->getSimpleAction() === Rule::CART_FIXED_ACTION && $address->getId()
                ? $this->walkConditions($rule, $address, 0.0, 0.0)
                : $this->walkConditions($rule, $address, $earlierBase, $earlier);
            $earlierBase += $itemDiscounts[(int)$rule->getId()][0] ?? 0.0;
            $earlier += $itemDiscounts[(int)$rule->getId()][1] ?? 0.0;
            if ($conditions->getWarning() !== null) {
                $warnings[] = $conditions->getWarning();
            }
            if (!$conditions->isValid()) {
                // The reason is written once category names are resolved.
                $verdicts[] = $this->verdict(
                    $rule,
                    Status::REJECTED,
                    Stage::CONDITIONS,
                    null,
                    [],
                    $conditions->getTree(),
                    null,
                    $freeShipping[(int)$rule->getId()] ?? null
                );
                continue;
            }

            $outcomes = [];
            foreach ($items as $item) {
                $outcome = $this->explainItem($rule, $item, $stoppedBy);
                if ($outcome->isApplied() && $rule->getStopRulesProcessing()) {
                    $stoppedBy[$this->itemKey($item)] = (int)$rule->getId();
                }
                $outcomes[] = $outcome;
            }
            $verdicts[] = $this->appliedOrRejected(
                $rule,
                $outcomes,
                $conditions->getTree(),
                $shippingAmounts[(int)$rule->getId()] ?? null,
                $freeShipping[(int)$rule->getId()] ?? null
            );
        }

        $verdicts = array_map(
            fn (RuleVerdict $verdict): RuleVerdict => $verdict->getStage() === Stage::CONDITIONS
                && $verdict->getConditions() !== null
                ? $verdict->withReason($this->conditionsReason($verdict->getConditions()))
                : $verdict,
            $this->categoryLabels->apply($verdicts, (int)$quote->getStoreId())
        );

        return new RulesExplanation($verdicts, array_merge($warnings, $this->reconcile($verdicts, $items)));
    }

    /**
     * Walk the conditions with the subtotal-with-discount core validates the rule against: earlier rules subtracted.
     *
     * @param Rule $rule
     * @param Address $address
     * @param float $earlierBase base item discounts of the rules processed before
     * @param float $earlier item discounts of the rules processed before
     * @return ConditionsResult
     */
    private function walkConditions(Rule $rule, Address $address, float $earlierBase, float $earlier): ConditionsResult
    {
        // Conditions read the data keys; the getters of the same name compute a different value.
        $saved = [
            'base_subtotal_with_discount' => $address->getData('base_subtotal_with_discount'),
            'subtotal_with_discount' => $address->getData('subtotal_with_discount'),
        ];
        $address->addData([
            'base_subtotal_with_discount' => (float)$address->getBaseSubtotal() - $earlierBase,
            'subtotal_with_discount' => (float)$address->getSubtotal() - $earlier,
        ]);
        try {
            return $this->walker->walkConditions($rule, $address);
        } finally {
            $address->addData($saved);
        }
    }

    /**
     * Outcome of one rule for one item, in the order Discount::collect and RulesApplier gate it.
     *
     * @param Rule $rule
     * @param AbstractItem $item
     * @param array $stoppedBy item key => rule ID that discarded subsequent rules
     * @return RuleItemOutcome
     */
    private function explainItem(Rule $rule, AbstractItem $item, array $stoppedBy): RuleItemOutcome
    {
        $itemId = (int)$item->getId();
        $sku = (string)$item->getSku();
        $label = $this->itemLabel->get($item);

        $stopper = $stoppedBy[$this->itemKey($item)] ?? null;
        if ($stopper !== null) {
            return new RuleItemOutcome(
                $itemId,
                $sku,
                false,
                Stage::STOPPED_BY,
                (string)__('Rule %1 applied earlier with "Discard subsequent rules".', $stopper),
                null,
                $stopper,
                null,
                $label
            );
        }
        if ($item->getNoDiscount()) {
            return $this->rejectItem($item, Stage::ITEM_EXCLUDED, __('The item has the "no discount" flag.'));
        }
        if (!$this->validator->canApplyDiscount($item)) {
            return $this->rejectItem($item, Stage::ITEM_EXCLUDED, __('A discount validator excludes the item.'));
        }
        $stepReason = $this->discountStepReason($rule, (float)$item->getQty());
        if ($stepReason !== null) {
            return $this->rejectItem($item, Stage::DISCOUNT_STEP, $stepReason);
        }

        $actions = $this->walkActions($rule, $item);
        if (!$actions->getResult()) {
            return new RuleItemOutcome(
                $itemId,
                $sku,
                false,
                Stage::ACTIONS,
                (string)__('The item does not match the "Apply to" conditions.'),
                null,
                null,
                $actions,
                $label
            );
        }

        $stage = null;
        $reason = null;
        $discountQty = (float)$rule->getDiscountQty();
        $totalQty = (float)$item->getTotalQty();
        if ($discountQty > 0 && $totalQty > $discountQty) {
            $stage = Stage::DISCOUNT_QTY;
            $reason = (string)__(
                'Discount applied to %1 of %2 units ("Maximum Qty Discount is Applied To").',
                (string)$discountQty,
                (string)$totalQty
            );
        }

        return new RuleItemOutcome(
            $itemId,
            $sku,
            true,
            $stage,
            $reason,
            -$this->itemDiscount($item, (int)$rule->getId()),
            null,
            $actions,
            $label,
            $this->childOutcomes($rule, $item)
        );
    }

    /**
     * Per-child outcomes when RulesApplier::applyRule discounts children one by one: the parent is
     * children-calculated (dynamic bundle) and does not match the actions itself; otherwise the parent
     * discount is distributed and the children need no rows.
     *
     * @param Rule $rule
     * @param AbstractItem $item
     * @return RuleItemOutcome[]
     */
    private function childOutcomes(Rule $rule, AbstractItem $item): array
    {
        $children = $item->getChildren();
        if ($children === [] || !$this->itemLabel->isChildrenCalculated($item)) {
            return [];
        }
        // Core validates a clone: no ID, no children.
        if ($this->walker->walkActions($rule, clone $item)->getResult()) {
            return [];
        }

        $outcomes = [];
        foreach ($children as $child) {
            $actions = $this->walker->walkActions($rule, $child);
            $applied = $actions->getResult();
            $outcomes[] = new RuleItemOutcome(
                (int)$child->getId(),
                (string)$child->getSku(),
                $applied,
                $applied ? null : Stage::ACTIONS,
                $applied ? null : (string)__('The item does not match the "Apply to" conditions.'),
                $applied ? -$this->itemDiscount($child, (int)$rule->getId()) : null,
                null,
                $actions,
                $this->itemLabel->get($child)
            );
        }

        return $outcomes;
    }

    /**
     * Rejected item outcome without an actions tree.
     *
     * @param AbstractItem $item
     * @param string $stage
     * @param \Stringable|string $reason
     * @return RuleItemOutcome
     */
    private function rejectItem(AbstractItem $item, string $stage, \Stringable|string $reason): RuleItemOutcome
    {
        return new RuleItemOutcome(
            (int)$item->getId(),
            (string)$item->getSku(),
            false,
            $stage,
            (string)$reason,
            null,
            null,
            null,
            $this->itemLabel->get($item)
        );
    }

    /**
     * Why discount_step skips the item, per action type as in Discount::collect.
     *
     * @param Rule $rule
     * @param float $qty
     * @return string|null
     */
    private function discountStepReason(Rule $rule, float $qty): ?string
    {
        $step = (float)$rule->getDiscountStep();
        $skip = match ((string)$rule->getSimpleAction()) {
            Rule::BY_PERCENT_ACTION, Rule::BY_FIXED_ACTION => $step > $qty,
            Rule::BUY_X_GET_Y_ACTION => $step >= $qty,
            default => false,
        };
        if (!$skip) {
            return null;
        }

        return (string)__(
            'Item qty %1 is below the discount step %2 ("%3").',
            (string)$qty,
            (string)$step,
            (string)$rule->getSimpleAction()
        );
    }

    /**
     * Actions tree of the item; a matching child counts for items that require children validation.
     *
     * @param Rule $rule
     * @param AbstractItem $item
     * @return ConditionNode
     */
    private function walkActions(Rule $rule, AbstractItem $item): ConditionNode
    {
        $tree = $this->walker->walkActions($rule, $item);
        if ($tree->getResult()
            || !$item instanceof Item
            || !$this->childrenValidationLocator->isChildrenValidationRequired($item)
        ) {
            return $tree;
        }
        foreach ($item->getChildren() as $child) {
            $childTree = $this->walker->walkActions($rule, $child);
            if ($childTree->getResult()) {
                return $childTree;
            }
        }

        return $tree;
    }

    /**
     * Free-shipping mode per rule ID that grants it, as Quote Shipping collector computes it.
     *
     * It runs after Discount::collect, so conditions see the subtotal minus all item discounts. Mirrors
     * OfflineShipping FreeShipping::isFreeShipping: parents in order, skipping no_discount ones, until an
     * address-mode rule matches; then applyToChildren processes every child of a children-calculated parent.
     *
     * @param Address $address
     * @param AbstractItem[] $items
     * @param Candidate[] $candidates
     * @param array $itemDiscounts rule ID => [base, amount] item discounts
     * @return array<int, string>
     */
    private function freeShippingModes(Address $address, array $items, array $candidates, array $itemDiscounts): array
    {
        $baseDiscount = array_sum(array_column($itemDiscounts, 0));
        $discount = array_sum(array_column($itemDiscounts, 1));
        $rules = [];
        foreach ($candidates as $candidate) {
            $rule = $candidate->getRule();
            if ($candidate->isCandidate()
                && (isset(FreeShipping::RULE_MODES[(int)$rule->getSimpleFreeShipping()])
                    || $rule->getStopRulesProcessing())
                && $this->walkConditions($rule, $address, $baseDiscount, $discount)->isValid()
            ) {
                $rules[] = $rule;
            }
        }
        if ($rules === []) {
            return [];
        }

        $modes = [];
        foreach ($items as $item) {
            if (!$item->getNoDiscount() && $this->processFreeShipping($rules, $item, $modes)) {
                break;
            }
        }
        foreach ($items as $item) {
            if ($item->getHasChildren() && $this->itemLabel->isChildrenCalculated($item)) {
                foreach ($item->getChildren() as $child) {
                    $this->processFreeShipping($rules, $child, $modes);
                }
            }
        }

        return $modes;
    }

    /**
     * OfflineShipping Calculator::processFreeShipping: actions only, stops at a matching "Discard subsequent rules".
     *
     * @param Rule[] $rules rules whose conditions pass
     * @param AbstractItem $item
     * @param array $modes rule ID => granted mode, filled in
     * @return bool whether an address-mode rule matched
     */
    private function processFreeShipping(array $rules, AbstractItem $item, array &$modes): bool
    {
        $address = false;
        foreach ($rules as $rule) {
            if (!$this->walker->walkActions($rule, $item)->getResult()) {
                continue;
            }
            $mode = FreeShipping::RULE_MODES[(int)$rule->getSimpleFreeShipping()] ?? null;
            if ($mode !== null) {
                $modes[(int)$rule->getId()] = $mode;
                $address = $address || $mode === FreeShipping::MODE_ADDRESS;
            }
            if ($rule->getStopRulesProcessing()) {
                break;
            }
        }

        return $address;
    }

    /**
     * Rule verdict from its item outcomes.
     *
     * @param Rule $rule
     * @param RuleItemOutcome[] $outcomes
     * @param ConditionNode $conditions
     * @param float|null $shippingAmount
     * @param string|null $freeShippingMode
     * @return RuleVerdict
     */
    private function appliedOrRejected(
        Rule $rule,
        array $outcomes,
        ConditionNode $conditions,
        ?float $shippingAmount,
        ?string $freeShippingMode
    ): RuleVerdict {
        $applied = array_values(array_filter($outcomes, static fn (RuleItemOutcome $o): bool => $o->isApplied()));
        if ($applied !== [] || $shippingAmount !== null) {
            $amount = array_sum(array_map(static fn (RuleItemOutcome $o): float => (float)$o->getAmount(), $applied));

            return $this->verdict(
                $rule,
                Status::APPLIED,
                null,
                null,
                $outcomes,
                $conditions,
                $amount + (float)$shippingAmount,
                $freeShippingMode,
                $shippingAmount
            );
        }

        if ($outcomes === []) {
            return $this->verdict(
                $rule,
                Status::REJECTED,
                Stage::ACTIONS,
                (string)__('The quote has no items.'),
                [],
                $conditions
            );
        }
        $stages = array_unique(array_map(static fn (RuleItemOutcome $o): ?string => $o->getStage(), $outcomes));
        $reasons = array_unique(array_map(static fn (RuleItemOutcome $o): ?string => $o->getReason(), $outcomes));

        return $this->verdict(
            $rule,
            Status::REJECTED,
            count($stages) === 1 ? $outcomes[0]->getStage() : $this->firstTopLevelStage($outcomes),
            count($reasons) === 1 ? $outcomes[0]->getReason() : (string)__('Not applied to any item, see the items.'),
            $outcomes,
            $conditions,
            null,
            $freeShippingMode
        );
    }

    /**
     * Stage of the first outcome that is not an item exclusion.
     *
     * @param RuleItemOutcome[] $outcomes
     * @return string|null
     */
    private function firstTopLevelStage(array $outcomes): ?string
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->getStage() !== Stage::ITEM_EXCLUDED) {
                return $outcome->getStage();
            }
        }

        return $outcomes[0]->getStage();
    }

    /**
     * Build a verdict with the rule's identity and flags.
     *
     * @param Rule $rule
     * @param string $status
     * @param string|null $stage
     * @param string|null $reason
     * @param RuleItemOutcome[] $items
     * @param ConditionNode|null $conditions
     * @param float|null $amount
     * @param string|null $freeShippingMode
     * @param float|null $shippingAmount
     * @return RuleVerdict
     */
    private function verdict(
        Rule $rule,
        string $status,
        ?string $stage,
        ?string $reason,
        array $items = [],
        ?ConditionNode $conditions = null,
        ?float $amount = null,
        ?string $freeShippingMode = null,
        ?float $shippingAmount = null
    ): RuleVerdict {
        return new RuleVerdict(
            (int)$rule->getId(),
            (string)$rule->getName(),
            (int)$rule->getSortOrder(),
            $status,
            $stage,
            $reason,
            $items,
            $amount,
            $conditions,
            (bool)$rule->getStopRulesProcessing(),
            $freeShippingMode,
            $shippingAmount
        );
    }

    /**
     * Distinct failing conditions of the tree as one sentence.
     *
     * @param ConditionNode $tree
     * @return string
     */
    private function conditionsReason(ConditionNode $tree): string
    {
        $failing = $tree->getFailureSummaries();

        return $failing === []
            ? (string)__('Conditions are not met.')
            : (string)__('Conditions are not met: %1.', implode('; ', $failing));
    }

    /**
     * Item discount of the rule from the item's "discounts" extension attribute.
     *
     * @param AbstractItem $item
     * @param int $ruleId
     * @return float
     */
    private function itemDiscount(AbstractItem $item, int $ruleId): float
    {
        $amount = 0.0;
        foreach ($item->getExtensionAttributes()?->getDiscounts() ?? [] as $discount) {
            if ((int)$discount->getRuleID() === $ruleId) {
                $amount += (float)$discount->getDiscountData()->getAmount();
            }
        }

        return $amount;
    }

    /**
     * Item discounts per rule ID as [base, amount] from the items' "discounts" extension attribute.
     *
     * @param AbstractItem[] $items
     * @return array<int, array{0: float, 1: float}>
     */
    private function itemDiscountsByRule(array $items): array
    {
        $sums = [];
        foreach ($items as $item) {
            foreach ($item->getExtensionAttributes()?->getDiscounts() ?? [] as $discount) {
                $ruleId = (int)$discount->getRuleID();
                $data = $discount->getDiscountData();
                $sums[$ruleId][0] = ($sums[$ruleId][0] ?? 0.0) + (float)$data->getBaseAmount();
                $sums[$ruleId][1] = ($sums[$ruleId][1] ?? 0.0) + (float)$data->getAmount();
            }
        }

        return $sums;
    }

    /**
     * Shipping discount per rule ID (negative) from the address "discounts" extension attribute.
     *
     * @param Address $address
     * @return array<int, float>
     */
    private function shippingDiscounts(Address $address): array
    {
        $amounts = [];
        foreach ($address->getExtensionAttributes()?->getDiscounts() ?? [] as $discount) {
            $data = $discount->getDiscountData();
            $appliedTo = method_exists($data, 'getAppliedTo') ? $data->getAppliedTo() : null;
            if ($appliedTo !== DiscountAppliedToInterface::APPLIED_TO_SHIPPING) {
                continue;
            }
            $ruleId = (int)$discount->getRuleID();
            $amounts[$ruleId] = ($amounts[$ruleId] ?? 0.0) - (float)$data->getAmount();
        }

        return $amounts;
    }

    /**
     * Compare the explained per-item verdicts with the item applied_rule_ids core produced.
     *
     * @param RuleVerdict[] $verdicts
     * @param AbstractItem[] $items visible items
     * @return Warning[]
     */
    private function reconcile(array $verdicts, array $items): array
    {
        $explained = [];
        foreach ($verdicts as $verdict) {
            $explained[$verdict->getRuleId()] = [];
            foreach ($verdict->getItems() as $outcome) {
                $explained[$verdict->getRuleId()][$outcome->getItemId()] = $outcome->isApplied();
            }
        }

        $warnings = [];
        foreach ($items as $item) {
            $core = array_map('intval', array_filter(explode(',', (string)$item->getAppliedRuleIds())));
            $ruleIds = array_unique([...array_keys($explained), ...$core]);
            foreach ($ruleIds as $ruleId) {
                $isExplained = $explained[$ruleId][(int)$item->getId()] ?? false;
                $isCore = in_array($ruleId, $core, true);
                if ($isExplained !== $isCore) {
                    $warnings[] = $this->mismatch($ruleId, $item, $isExplained, $isCore, $verdicts);
                }
            }
        }

        return $warnings;
    }

    /**
     * Warning for a rule/item pair where core decided otherwise.
     *
     * @param int $ruleId
     * @param AbstractItem $item
     * @param bool $explained
     * @param bool $core
     * @param RuleVerdict[] $verdicts
     * @return Warning
     */
    private function mismatch(int $ruleId, AbstractItem $item, bool $explained, bool $core, array $verdicts): Warning
    {
        $name = (string)$ruleId;
        foreach ($verdicts as $verdict) {
            if ($verdict->getRuleId() === $ruleId) {
                $name = $verdict->getName();
            }
        }

        return new Warning(
            self::WARNING_CODE,
            (string)__(
                'Core decided otherwise for rule "%1" on "%2" (explained: %3, core: %4)'
                . ' — a third-party plugin or observer may change discounts.',
                $name,
                $this->itemLabel->get($item),
                $explained ? 'applied' : 'not applied',
                $core ? 'applied' : 'not applied'
            ),
            ['rule_id' => $ruleId, 'item_id' => (int)$item->getId(), 'sku' => (string)$item->getSku()]
        );
    }

    /**
     * Stable key of an item within one quote.
     *
     * @param AbstractItem $item
     * @return string
     */
    private function itemKey(AbstractItem $item): string
    {
        return $item->getId() !== null ? (string)$item->getId() : 'o' . spl_object_id($item);
    }
}
