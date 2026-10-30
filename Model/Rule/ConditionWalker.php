<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use DmLab\PriceExplain\Model\Quote\ItemLabel;
use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\Warning;
use DmLab\PriceExplain\Model\Trace\Tracer;
use Magento\Framework\Model\AbstractModel;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Combine;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Address as AddressCondition;
use Magento\SalesRule\Model\Rule\Condition\Combine as SalesRuleCombine;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use Magento\SalesRule\Model\Rule\Condition\Product\Combine as ProductCombine;
use Magento\SalesRule\Model\Rule\Condition\Product\Found;
use Magento\SalesRule\Model\Rule\Condition\Product\Subselect;

/**
 * Evaluates every node of a cart rule's conditions/actions tree (no short-circuit) into a ConditionNode tree.
 */
class ConditionWalker
{
    public const WARNING_CODE = 'condition_mismatch';

    /**
     * @param Tracer $tracer
     * @param ValueLabels $valueLabels
     * @param ItemLabel $itemLabel
     * @param SalesRuleVersion $salesRuleVersion
     */
    public function __construct(
        private readonly Tracer $tracer,
        private readonly ValueLabels $valueLabels,
        private readonly ItemLabel $itemLabel,
        private readonly SalesRuleVersion $salesRuleVersion
    ) {
    }

    /**
     * Walk the rule conditions against the address; the verdict comes from the rule's own validate().
     *
     * @param Rule $rule
     * @param Address $address
     * @return ConditionsResult
     */
    public function walkConditions(Rule $rule, Address $address): ConditionsResult
    {
        $this->tracer->beginCapture();
        // Same totals swap as Utility::canProcessRule: item-restricted rules see eligible items only.
        $saved = $this->salesRuleVersion->countsEligibleItemsOnly() && $this->hasItemRestrictions($rule)
            ? $this->swapEligibleTotals($rule, $address)
            : null;
        try {
            $tree = $this->walkNode($rule->getConditions(), $address, '1');
            $valid = (bool)$rule->validate($address);
        } finally {
            if ($saved !== null) {
                $address->addData($saved);
            }
            $this->tracer->endCapture();
        }

        $warning = null;
        if ($tree->getResult() !== $valid) {
            $warning = new Warning(
                self::WARNING_CODE,
                (string)__(
                    'Rule "%1": the condition breakdown says %2, but the rule validation says %3.',
                    (string)$rule->getName(),
                    $tree->getResult() ? 'true' : 'false',
                    $valid ? 'true' : 'false'
                ),
                ['rule_id' => (int)$rule->getId()]
            );
        }

        return new ConditionsResult($tree, $valid, $warning);
    }

    /**
     * Walk the rule actions ("apply to items" conditions) against one item.
     *
     * @param Rule $rule
     * @param AbstractItem $item
     * @return ConditionNode
     */
    public function walkActions(Rule $rule, AbstractItem $item): ConditionNode
    {
        $this->tracer->beginCapture();
        try {
            return $this->walkNode($rule->getActions(), $item, '1');
        } finally {
            $this->tracer->endCapture();
        }
    }

    /**
     * Dispatch a node to its evaluator.
     *
     * @param AbstractCondition $node
     * @param AbstractModel $model
     * @param string $path
     * @return ConditionNode
     */
    private function walkNode(AbstractCondition $node, AbstractModel $model, string $path): ConditionNode
    {
        if ($node instanceof Found) {
            return $this->walkFound($node, $model, $path);
        }
        if ($node instanceof Subselect) {
            return $this->walkSubselect($node, $model, $path);
        }
        if ($node instanceof ProductCombine || $node instanceof SalesRuleCombine) {
            [$children, $result] = $this->walkChildren($node, $model, $path);

            return new ConditionNode(
                $path,
                $this->describe($node),
                $result,
                true,
                (string)$node->getAggregator(),
                (bool)$node->getValue(),
                null,
                $children
            );
        }
        if ($node instanceof AddressCondition || $node instanceof ProductCondition) {
            return $this->walkLeaf($node, $model, $path);
        }

        return $this->walkOpaque($node, $model, $path);
    }

    /**
     * Evaluate every child of a combine and apply all/any + true/false like Combine::_isValid.
     *
     * @param Combine $node
     * @param AbstractModel $model
     * @param string $path
     * @return array{0: ConditionNode[], 1: bool}
     */
    private function walkChildren(Combine $node, AbstractModel $model, string $path): array
    {
        $all = $node->getAggregator() === 'all';
        $true = (bool)$node->getValue();
        $children = [];
        foreach (array_values((array)$node->getConditions()) as $i => $condition) {
            $childPath = $path . '--' . ($i + 1);
            $children[] = $node instanceof ProductCombine
                ? $this->walkOverEntities($node, $condition, $model, $childPath)
                : $this->walkNode($condition, $model, $childPath);
        }
        if ($children === []) {
            return [[], true];
        }
        $matches = array_map(static fn (ConditionNode $c): bool => $c->getResult() === $true, $children);
        $result = $all ? !in_array(false, $matches, true) : in_array(true, $matches, true);

        return [$children, $result];
    }

    /**
     * Product\Combine validates a child against the item, its parent or its children (attribute scope).
     *
     * @param ProductCombine $parent
     * @param AbstractCondition $condition
     * @param AbstractModel $item
     * @param string $path
     * @return ConditionNode
     */
    private function walkOverEntities(
        ProductCombine $parent,
        AbstractCondition $condition,
        AbstractModel $item,
        string $path
    ): ConditionNode {
        $true = (bool)$parent->getValue();
        $scope = $condition->getAttributeScope();
        if ($scope === 'parent') {
            $entities = [$item->getParentItem() ?: $item];
        } elseif ($scope === 'children') {
            $entities = $item->getChildren() ?: [$item];
        } else {
            $entities = $item->getChildren() ?: [];
            $entities[] = $item;
        }
        $node = null;
        foreach ($entities as $entity) {
            $node = $this->walkNode($condition, $entity, $path);
            if ($node->getResult() === $true) {
                break;
            }
        }

        return $entity === $item || !$entity instanceof AbstractItem
            ? $node
            : $node->withItem($this->itemLabel->get($entity));
    }

    /**
     * "Product found / not found in cart": per-item breakdown, matching item IDs as the actual value.
     *
     * @param Found $node
     * @param AbstractModel $address
     * @param string $path
     * @return ConditionNode
     */
    private function walkFound(Found $node, AbstractModel $address, string $path): ConditionNode
    {
        [$items, $matching] = $this->walkItems($node, $address->getAllItems(), $path);

        return new ConditionNode(
            $path,
            (string)__(
                'If an item is %1 in the cart with %2 of these conditions true:',
                (string)$node->getValueName(),
                (string)$node->getAggregatorName()
            ),
            (bool)$node->validate($address),
            true,
            (string)$node->getAggregator(),
            null,
            $matching,
            $items
        );
    }

    /**
     * Subselection total: the aggregated value comes from validateAttribute(), items are broken down.
     *
     * @param Subselect $node
     * @param AbstractModel $address
     * @param string $path
     * @return ConditionNode
     */
    private function walkSubselect(Subselect $node, AbstractModel $address, string $path): ConditionNode
    {
        $quote = $address->getQuote();
        $all = $quote->getIsMultiShipping() ? $address->getAllItems() : $quote->getAllVisibleItems();
        [$items] = $this->walkItems($node, $all, $path);
        $this->tracer->forgetValidatedValue($node);
        $result = (bool)$node->validate($address);
        $actual = $this->tracer->hasValidatedValue($node) ? $this->tracer->getValidatedValue($node) : null;

        return new ConditionNode(
            $path,
            (string)__(
                'If %1 %2 %3 for a subselection of items in cart matching %4 of these conditions:',
                (string)$node->getAttributeName(),
                (string)$node->getOperatorName(),
                (string)$node->getValueName(),
                (string)$node->getAggregatorName()
            ),
            $result,
            true,
            (string)$node->getAggregator(),
            $node->getValueParsed(),
            $actual,
            $items,
            null,
            null,
            null,
            $this->valueLabels->labels($node, $node->getValueParsed(), false),
            $this->valueLabels->labels($node, $actual, true),
            $this->valueLabels->inputType($node)
        );
    }

    /**
     * Synthetic nodes: the children of Found/Subselect evaluated per visible item, its child items folded in.
     *
     * Core matches per item, so an item check yields the node's value when any of its members does;
     * folded rows show the first such member's evaluation (else the visible item's) so failures stay traceable.
     *
     * @param ProductCombine $node
     * @param iterable<AbstractItem> $items
     * @param string $path
     * @return array{0: ConditionNode[], 1: int[]} item checks and IDs of the items yielding the node's value
     */
    private function walkItems(ProductCombine $node, iterable $items, string $path): array
    {
        $true = (bool)$node->getValue();
        $groups = [];
        foreach ($items as $item) {
            $visible = $item->getParentItem() ?: $item;
            $groups[spl_object_id($visible)]['visible'] = $visible;
            $groups[spl_object_id($visible)]['members'][] = $item;
        }

        $checks = [];
        $matching = [];
        foreach ($groups as ['visible' => $visible, 'members' => $members]) {
            usort($members, static fn ($a, $b): int => (int)($a !== $visible) <=> (int)($b !== $visible));
            $childLists = [];
            $labels = [];
            $result = !$true;
            $decider = null;
            foreach ($members as $i => $member) {
                [$childLists[], $memberResult] = $this->walkChildren($node, $member, $path);
                $labels[] = $this->itemLabel->ownSku($member);
                if ($memberResult === $true) {
                    $matching[] = (int)$member->getId();
                    $result = $true;
                    $decider ??= $i;
                }
            }
            $label = $this->itemLabel->get($visible);
            $checks[] = new ConditionNode(
                $path . '@' . (int)$visible->getId(),
                $label,
                $result,
                true,
                (string)$node->getAggregator(),
                $true,
                null,
                $this->foldChildren($childLists, $labels, $decider ?? 0),
                null,
                $label,
                null,
                null,
                null,
                null,
                array_map(static fn (AbstractItem $m): int => (int)$m->getId(), $members)
            );
        }

        return [$checks, $matching];
    }

    /**
     * Fold children lists evaluated against several items, position by position.
     *
     * @param ConditionNode[][] $childLists one per item, same tree shape
     * @param string[] $labels item labels, same order
     * @param int $decider index of the item deciding the group's result
     * @return ConditionNode[]
     */
    private function foldChildren(array $childLists, array $labels, int $decider): array
    {
        return array_map(
            fn (int|string $i): ConditionNode => $this->fold(array_column($childLists, $i), $labels, $decider),
            array_keys($childLists[0])
        );
    }

    /**
     * Fold one node evaluated against several items; result and item label come from the deciding item.
     *
     * Equal evaluations become one node, differing leaves list each item's value.
     *
     * @param ConditionNode[] $nodes one per item, same tree shape
     * @param string[] $labels item labels, same order
     * @param int $decider index of the item deciding the group's result
     * @return ConditionNode
     */
    private function fold(array $nodes, array $labels, int $decider): ConditionNode
    {
        $base = $nodes[$decider];
        if (count($nodes) === 1) {
            return $base;
        }
        $children = $this->foldChildren(
            array_map(static fn (ConditionNode $n): array => $n->getChildren(), $nodes),
            $labels,
            $decider
        );
        $results = array_map(static fn (ConditionNode $n): bool => $n->getResult(), $nodes);
        $same = count(array_unique($results)) === 1;
        foreach ($nodes as $node) {
            $same = $same && $node->getActual() === $base->getActual();
        }
        if ($same && $children === $base->getChildren()) {
            return $base;
        }
        $result = $base->getResult();
        if ($children !== []) {
            return $base->withFoldedChildren($result, $children);
        }

        $members = [];
        foreach ($nodes as $i => $node) {
            $members[] = [
                'label' => $labels[$i],
                'actual' => $node->getActual(),
                'actual_label' => $node->getActualLabel(),
                'result' => $node->getResult(),
            ];
        }
        $actualLabels = array_map(static fn (ConditionNode $n): ?array => $n->getActualLabels(), $nodes);

        return $base->withMemberActuals($result, $members, $this->valueLabels->merge($actualLabels));
    }

    /**
     * Leaf: result from the node's own validate(), actual value from the capture plugin.
     *
     * @param AbstractCondition $node
     * @param AbstractModel $model
     * @param string $path
     * @return ConditionNode
     */
    private function walkLeaf(AbstractCondition $node, AbstractModel $model, string $path): ConditionNode
    {
        $this->tracer->forgetValidatedValue($node);
        $result = (bool)$node->validate($model);
        $actual = $this->tracer->hasValidatedValue($node)
            ? $this->tracer->getValidatedValue($node)
            : $this->fallbackValue($node, $model);
        $expected = $node->getValueParsed();
        $inputType = $this->valueLabels->inputType($node);

        return new ConditionNode(
            $path,
            $this->describe($node),
            $result,
            true,
            null,
            $expected,
            $actual,
            [],
            null,
            null,
            // asString() shows category IDs; the subject lets the node text show names instead.
            $inputType === ValueLabels::INPUT_CATEGORY ? $this->subject($node) : null,
            $this->valueLabels->labels($node, $expected, false),
            $this->valueLabels->labels($node, $actual, true),
            $inputType
        );
    }

    /**
     * Third-party node: result only.
     *
     * @param AbstractCondition $node
     * @param AbstractModel $model
     * @param string $path
     * @return ConditionNode
     */
    private function walkOpaque(AbstractCondition $node, AbstractModel $model, string $path): ConditionNode
    {
        return new ConditionNode(
            $path,
            $this->describe($node),
            (bool)$node->validate($model),
            true,
            null,
            null,
            null,
            [],
            (string)__('No explanation available for %1.', $this->realClass($node))
        );
    }

    /**
     * Compared value when validateAttribute() was not reached: the attribute of the validated model.
     *
     * @param AbstractCondition $node
     * @param AbstractModel $model
     * @return mixed
     */
    private function fallbackValue(AbstractCondition $node, AbstractModel $model): mixed
    {
        $source = $model;
        if ($node instanceof ProductCondition && $model instanceof AbstractItem) {
            $source = $model->getProduct();
        } elseif ($node instanceof AddressCondition && !$model instanceof Address) {
            $quote = $model->getQuote();
            $source = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        }

        return $source?->getData((string)$node->getAttribute());
    }

    /**
     * Node description; never fails on a broken third-party asString().
     *
     * @param AbstractCondition $node
     * @return string
     */
    private function describe(AbstractCondition $node): string
    {
        try {
            return trim((string)$node->asString());
        } catch (\Throwable $e) {
            return $this->realClass($node);
        }
    }

    /**
     * "Attribute operator" part of the description, e.g. "Category is one of".
     *
     * @param AbstractCondition $node
     * @return string|null
     */
    private function subject(AbstractCondition $node): ?string
    {
        try {
            return trim((string)$node->getAttributeName() . ' ' . (string)$node->getOperatorName());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Class name without the generated interceptor suffix.
     *
     * @param object $node
     * @return string
     */
    private function realClass(object $node): string
    {
        $class = get_class($node);

        return str_ends_with($class, '\\Interceptor') ? substr($class, 0, -strlen('\\Interceptor')) : $class;
    }

    /**
     * Whether the rule restricts discounted items (non-empty actions tree).
     *
     * @param Rule $rule
     * @return bool
     */
    private function hasItemRestrictions(Rule $rule): bool
    {
        try {
            $actions = $rule->getActions();
        } catch (\Throwable $e) {
            return false;
        }

        return $actions instanceof Combine && !empty($actions->getConditions()) && is_array($actions->getConditions());
    }

    /**
     * Put totals of action-eligible items on the address; returns the original values or null when none match.
     *
     * @param Rule $rule
     * @param Address $address
     * @return array<string, mixed>|null
     */
    private function swapEligibleTotals(Rule $rule, Address $address): ?array
    {
        $subtotal = $subtotalInclTax = $qty = $weight = 0.0;
        $eligible = false;
        foreach ($address->getAllItems() as $item) {
            if (($item->getParentItem() && $item->getParentItem()->getProductType() === 'configurable')
                || (($item->getHasChildren() || $item->getChildren()) && $item->isChildrenCalculated())
                || $item->getNoDiscount()
                || !$rule->getActions()->validate($item)
            ) {
                continue;
            }
            $eligible = true;
            $subtotal += (float)$item->getBaseRowTotal();
            $subtotalInclTax += (float)$item->getBaseRowTotalInclTax();
            $qty += (float)$item->getQty();
            $weight += (float)$item->getRowWeight();
        }
        if (!$eligible) {
            return null;
        }
        $saved = [
            'base_subtotal' => $address->getBaseSubtotal(),
            'base_subtotal_with_discount' => $address->getData('base_subtotal_with_discount'),
            'base_subtotal_total_incl_tax' => $address->getBaseSubtotalTotalInclTax(),
            'total_qty' => $address->getTotalQty(),
            'weight' => $address->getWeight(),
        ];
        $address->addData([
            'base_subtotal' => $subtotal,
            'base_subtotal_with_discount' => $subtotal,
            'base_subtotal_total_incl_tax' => $subtotalInclTax,
            'total_qty' => $qty,
            'weight' => $weight,
        ]);

        return $saved;
    }
}
