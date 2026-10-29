<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Verdict of one cart price rule for the quote: applied, or rejected with the stage and reason.
 */
class RuleVerdict
{
    use FormatsAmounts;

    /**
     * @param int $ruleId
     * @param string $name
     * @param int $sortOrder
     * @param string $status Status::*
     * @param string|null $stage Stage::* when rejected
     * @param string|null $reason
     * @param RuleItemOutcome[] $items per-item outcomes
     * @param float|null $amount total discount of the rule
     * @param ConditionNode|null $conditions conditions tree evaluated against the address
     * @param bool $stopRulesProcessing "Discard subsequent rules"
     * @param string|null $freeShippingMode FreeShipping::MODE_* when the rule grants free shipping
     * @param float|null $shippingAmount shipping discount of the rule
     * @param string|null $freeShippingOutcome FreeShipping::* verdict code once the free-shipping section is built
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly int $ruleId,
        private readonly string $name,
        private readonly int $sortOrder,
        private readonly string $status,
        private readonly ?string $stage = null,
        private readonly ?string $reason = null,
        private readonly array $items = [],
        private readonly ?float $amount = null,
        private readonly ?ConditionNode $conditions = null,
        private readonly bool $stopRulesProcessing = false,
        private readonly ?string $freeShippingMode = null,
        private readonly ?float $shippingAmount = null,
        private readonly ?string $freeShippingOutcome = null
    ) {
        if (!in_array($status, Status::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown rule status "%s".', $status));
        }
        if ($stage !== null && !in_array($stage, Stage::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown rule stage "%s".', $stage));
        }
    }

    /**
     * Rule ID.
     *
     * @return int
     */
    public function getRuleId(): int
    {
        return $this->ruleId;
    }

    /**
     * Rule name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Rule priority.
     *
     * @return int
     */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * Status::* value.
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
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
     * Per-item outcomes.
     *
     * @return RuleItemOutcome[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Total discount of the rule.
     *
     * @return float|null
     */
    public function getAmount(): ?float
    {
        return $this->amount;
    }

    /**
     * Evaluated conditions tree.
     *
     * @return ConditionNode|null
     */
    public function getConditions(): ?ConditionNode
    {
        return $this->conditions;
    }

    /**
     * Whether the rule discards subsequent rules.
     *
     * @return bool
     */
    public function isStopRulesProcessing(): bool
    {
        return $this->stopRulesProcessing;
    }

    /**
     * Whether the rule grants free shipping.
     *
     * @return bool
     */
    public function isFreeShipping(): bool
    {
        return $this->freeShippingMode !== null;
    }

    /**
     * Free-shipping mode: FreeShipping::MODE_ITEMS or MODE_ADDRESS.
     *
     * @return string|null
     */
    public function getFreeShippingMode(): ?string
    {
        return $this->freeShippingMode;
    }

    /**
     * Free-shipping verdict code of the quote this rule granted free shipping to.
     *
     * @return string|null
     */
    public function getFreeShippingOutcome(): ?string
    {
        return $this->freeShippingOutcome;
    }

    /**
     * Copy with the free-shipping verdict code.
     *
     * @param string $outcome
     * @return RuleVerdict
     */
    public function withFreeShippingOutcome(string $outcome): RuleVerdict
    {
        return new self(
            $this->ruleId,
            $this->name,
            $this->sortOrder,
            $this->status,
            $this->stage,
            $this->reason,
            $this->items,
            $this->amount,
            $this->conditions,
            $this->stopRulesProcessing,
            $this->freeShippingMode,
            $this->shippingAmount,
            $outcome
        );
    }

    /**
     * Copy with another reason.
     *
     * @param string|null $reason
     * @return RuleVerdict
     */
    public function withReason(?string $reason): RuleVerdict
    {
        return new self(
            $this->ruleId,
            $this->name,
            $this->sortOrder,
            $this->status,
            $this->stage,
            $reason,
            $this->items,
            $this->amount,
            $this->conditions,
            $this->stopRulesProcessing,
            $this->freeShippingMode,
            $this->shippingAmount,
            $this->freeShippingOutcome
        );
    }

    /**
     * Conditions tree and the actions trees of all item outcomes.
     *
     * @return ConditionNode[]
     */
    public function getConditionTrees(): array
    {
        $trees = $this->conditions !== null ? [$this->conditions] : [];
        foreach ($this->items as $item) {
            array_push($trees, ...$item->getActionTrees());
        }

        return $trees;
    }

    /**
     * Copy with the conditions and actions trees passed through $map.
     *
     * @param callable $map (ConditionNode): ConditionNode
     * @return RuleVerdict
     */
    public function mapConditionTrees(callable $map): RuleVerdict
    {
        return new self(
            $this->ruleId,
            $this->name,
            $this->sortOrder,
            $this->status,
            $this->stage,
            $this->reason,
            array_map(static fn (RuleItemOutcome $item): RuleItemOutcome => $item->mapActions($map), $this->items),
            $this->amount,
            $this->conditions !== null ? $map($this->conditions) : null,
            $this->stopRulesProcessing,
            $this->freeShippingMode,
            $this->shippingAmount,
            $this->freeShippingOutcome
        );
    }

    /**
     * Shipping discount of the rule.
     *
     * @return float|null
     */
    public function getShippingAmount(): ?float
    {
        return $this->shippingAmount;
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
            'id' => $this->ruleId,
            'name' => $this->name,
            'sort_order' => $this->sortOrder,
            'status' => $this->status,
            'status_label' => Labels::status($this->status),
            'stage' => $this->stage,
            'stage_label' => $this->stage !== null ? Labels::stage($this->stage) : null,
            'reason' => $this->reason,
            'amount' => $this->formatAmount($this->amount, $precision),
            'shipping_amount' => $this->formatAmount($this->shippingAmount, $precision),
            'stop_rules_processing' => $this->stopRulesProcessing,
            'free_shipping' => $this->freeShippingMode,
            'free_shipping_label' => $this->freeShippingMode !== null
                ? Labels::freeShippingMode($this->freeShippingMode)
                : null,
            'free_shipping_outcome' => $this->freeShippingOutcome,
            'free_shipping_outcome_label' => $this->freeShippingOutcome !== null
                ? Labels::freeShippingVerdict($this->freeShippingOutcome)
                : null,
            'conditions' => $this->conditions?->toArray(),
            'items' => array_map(static fn (RuleItemOutcome $i): array => $i->toArray($precision), $this->items),
        ];
    }
}
