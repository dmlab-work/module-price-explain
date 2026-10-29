<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Outcome of one cart price rule for one quote item.
 */
class RuleItemOutcome
{
    use FormatsAmounts;

    /**
     * @param int $itemId
     * @param string $sku
     * @param bool $applied
     * @param string|null $stage Stage::* when not applied, or discount_qty when applied to part of the qty
     * @param string|null $reason
     * @param float|null $amount discount on this item
     * @param int|null $stoppedBy rule ID that discarded subsequent rules
     * @param ConditionNode|null $actions actions tree evaluated against the item
     * @param string|null $label item label, defaults to the SKU
     * @param RuleItemOutcome[] $children per-child outcomes when the discount is calculated per child
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly int $itemId,
        private readonly string $sku,
        private readonly bool $applied,
        private readonly ?string $stage = null,
        private readonly ?string $reason = null,
        private readonly ?float $amount = null,
        private readonly ?int $stoppedBy = null,
        private readonly ?ConditionNode $actions = null,
        private readonly ?string $label = null,
        private readonly array $children = []
    ) {
        if ($stage !== null && !in_array($stage, Stage::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown rule stage "%s".', $stage));
        }
    }

    /**
     * Quote item ID.
     *
     * @return int
     */
    public function getItemId(): int
    {
        return $this->itemId;
    }

    /**
     * Item SKU.
     *
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * Item label (parent SKU → child SKU).
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label ?? $this->sku;
    }

    /**
     * Per-child outcomes of a parent whose discount is calculated per child.
     *
     * @return RuleItemOutcome[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Whether the rule applied to the item.
     *
     * @return bool
     */
    public function isApplied(): bool
    {
        return $this->applied;
    }

    /**
     * Rejection stage.
     *
     * @return string|null
     */
    public function getStage(): ?string
    {
        return $this->stage;
    }

    /**
     * Rejection reason.
     *
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Discount on this item.
     *
     * @return float|null
     */
    public function getAmount(): ?float
    {
        return $this->amount;
    }

    /**
     * Rule that discarded subsequent rules for this item.
     *
     * @return int|null
     */
    public function getStoppedBy(): ?int
    {
        return $this->stoppedBy;
    }

    /**
     * Evaluated actions tree.
     *
     * @return ConditionNode|null
     */
    public function getActions(): ?ConditionNode
    {
        return $this->actions;
    }

    /**
     * Actions trees of the outcome and its children.
     *
     * @return ConditionNode[]
     */
    public function getActionTrees(): array
    {
        $trees = $this->actions !== null ? [$this->actions] : [];
        foreach ($this->children as $child) {
            array_push($trees, ...$child->getActionTrees());
        }

        return $trees;
    }

    /**
     * Copy with the actions trees of the outcome and its children passed through $map.
     *
     * @param callable $map (ConditionNode): ConditionNode
     * @return RuleItemOutcome
     */
    public function mapActions(callable $map): RuleItemOutcome
    {
        return new self(
            $this->itemId,
            $this->sku,
            $this->applied,
            $this->stage,
            $this->reason,
            $this->amount,
            $this->stoppedBy,
            $this->actions !== null ? $map($this->actions) : null,
            $this->label,
            array_map(static fn (RuleItemOutcome $child): RuleItemOutcome => $child->mapActions($map), $this->children)
        );
    }

    /**
     * JSON-ready representation.
     *
     * @param int $precision
     * @return array<string, mixed>
     */
    public function toArray(int $precision = 2): array
    {
        return [
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'label' => $this->getLabel(),
            'applied' => $this->applied,
            'stage' => $this->stage,
            'stage_label' => $this->stage !== null ? Labels::stage($this->stage) : null,
            'reason' => $this->reason,
            'amount' => $this->formatAmount($this->amount, $precision),
            'stopped_by' => $this->stoppedBy,
            'actions' => $this->actions?->toArray(),
            'children' => array_map(
                static fn (RuleItemOutcome $child): array => $child->toArray($precision),
                $this->children
            ),
        ];
    }
}
