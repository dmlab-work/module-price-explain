<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * One node of an evaluated rule condition tree.
 */
class ConditionNode
{
    /**
     * @param string $path node path in the rule tree (1--1--2)
     * @param string $description human-readable condition (asString())
     * @param bool $result
     * @param bool $evaluated false when the result could not be computed for this node
     * @param string|null $aggregator all|any for combine nodes
     * @param mixed $expected configured value (scalar, array or null; TRUE/FALSE setting of a combine)
     * @param mixed $actual compared value (scalar, array or null)
     * @param ConditionNode[] $children
     * @param string|null $note e.g. "no explanation available"
     * @param string|null $item label of the item the node was evaluated against
     * @param string|null $subject "attribute operator" text; with expected labels it replaces the raw description
     * @param string[]|null $expectedLabels display labels of the configured value
     * @param string[]|null $actualLabels display labels of the compared value, de-duplicated and sorted
     * @param string|null $inputType condition input type (category, select, boolean, …)
     * @param int[]|null $itemIds quote item IDs folded into an item check (visible item and its children)
     * @param array[]|null $memberActuals per-item values of a folded node whose items disagree:
     *        [{label, actual, actual_label, result}]
     */
    public function __construct(
        private readonly string $path,
        private readonly string $description,
        private readonly bool $result,
        private readonly bool $evaluated = true,
        private readonly ?string $aggregator = null,
        private readonly mixed $expected = null,
        private readonly mixed $actual = null,
        private readonly array $children = [],
        private readonly ?string $note = null,
        private readonly ?string $item = null,
        private readonly ?string $subject = null,
        private readonly ?array $expectedLabels = null,
        private readonly ?array $actualLabels = null,
        private readonly ?string $inputType = null,
        private readonly ?array $itemIds = null,
        private readonly ?array $memberActuals = null
    ) {
    }

    /**
     * Node path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Condition description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Node result.
     *
     * @return bool
     */
    public function getResult(): bool
    {
        return $this->result;
    }

    /**
     * Whether the node was evaluated.
     *
     * @return bool
     */
    public function isEvaluated(): bool
    {
        return $this->evaluated;
    }

    /**
     * Aggregator (all|any) of combine nodes.
     *
     * @return string|null
     */
    public function getAggregator(): ?string
    {
        return $this->aggregator;
    }

    /**
     * Configured value.
     *
     * @return mixed
     */
    public function getExpected(): mixed
    {
        return $this->expected;
    }

    /**
     * Compared value.
     *
     * @return mixed
     */
    public function getActual(): mixed
    {
        return $this->actual;
    }

    /**
     * Child nodes.
     *
     * @return ConditionNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Explanatory note.
     *
     * @return string|null
     */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * Label of the item the node was evaluated against.
     *
     * @return string|null
     */
    public function getItem(): ?string
    {
        return $this->item;
    }

    /**
     * Condition input type.
     *
     * @return string|null
     */
    public function getInputType(): ?string
    {
        return $this->inputType;
    }

    /**
     * Quote item IDs folded into an item check.
     *
     * @return int[]|null
     */
    public function getItemIds(): ?array
    {
        return $this->itemIds;
    }

    /**
     * Per-item values of a folded node whose items disagree.
     *
     * @return array<int, array{label: string, actual: mixed, actual_label: string|null, result: bool}>|null
     */
    public function getMemberActuals(): ?array
    {
        return $this->memberActuals;
    }

    /**
     * Display labels of the configured value.
     *
     * @return string[]|null
     */
    public function getExpectedLabels(): ?array
    {
        return $this->expectedLabels;
    }

    /**
     * Display labels of the compared value.
     *
     * @return string[]|null
     */
    public function getActualLabels(): ?array
    {
        return $this->actualLabels;
    }

    /**
     * Configured value as display text: "a" or "[a, b]"; null without labels.
     *
     * @return string|null
     */
    public function getExpectedLabel(): ?string
    {
        return $this->joinLabels($this->expectedLabels, $this->expected);
    }

    /**
     * Compared value as display text: "a" or "[a, b]"; null without labels.
     *
     * @return string|null
     */
    public function getActualLabel(): ?string
    {
        return $this->joinLabels($this->actualLabels, $this->actual);
    }

    /**
     * Readable condition: the description, with labels instead of raw IDs when the subject is known.
     *
     * @return string
     */
    public function getText(): string
    {
        return $this->subject !== null && $this->expectedLabels !== null
            ? $this->subject . ' ' . implode(', ', $this->expectedLabels)
            : $this->description;
    }

    /**
     * Copy with other value labels.
     *
     * @param string[]|null $expectedLabels
     * @param string[]|null $actualLabels
     * @return ConditionNode
     */
    public function withLabels(?array $expectedLabels, ?array $actualLabels): ConditionNode
    {
        return $this->copy(['expectedLabels' => $expectedLabels, 'actualLabels' => $actualLabels]);
    }

    /**
     * Copy with the label of the item the node was evaluated against.
     *
     * @param string $item
     * @return ConditionNode
     */
    public function withItem(string $item): ConditionNode
    {
        return $this->copy(['item' => $item]);
    }

    /**
     * Copy of a group folding several items' evaluations: merged result and folded children.
     *
     * @param bool $result
     * @param ConditionNode[] $children
     * @return ConditionNode
     */
    public function withFoldedChildren(bool $result, array $children): ConditionNode
    {
        return $this->copy(['result' => $result, 'children' => $children]);
    }

    /**
     * Copy of a leaf folding several items' evaluations: merged result, per-item values and their merged labels.
     *
     * @param bool $result
     * @param array[] $members [{label, actual, actual_label, result}]
     * @param string[]|null $actualLabels
     * @return ConditionNode
     */
    public function withMemberActuals(bool $result, array $members, ?array $actualLabels): ConditionNode
    {
        return $this->copy(['result' => $result, 'memberActuals' => $members, 'actualLabels' => $actualLabels]);
    }

    /**
     * Rebuild the tree bottom-up through $map.
     *
     * @param callable $map (ConditionNode): ConditionNode
     * @return ConditionNode
     */
    public function map(callable $map): ConditionNode
    {
        $children = array_map(static fn (ConditionNode $c): ConditionNode => $c->map($map), $this->children);

        return $map($this->copy(['children' => $children]));
    }

    /**
     * The node and all its descendants, depth-first.
     *
     * @return ConditionNode[]
     */
    public function flatten(): array
    {
        $nodes = [$this];
        foreach ($this->children as $child) {
            array_push($nodes, ...$child->flatten());
        }

        return $nodes;
    }

    /**
     * Failing leaves (differing from the wanted result, depth-first) with the item they were evaluated against.
     *
     * A group's boolean expected value is its TRUE/FALSE setting; a node with children and a scalar expected value
     * (subselection) is a leaf. Inside FOUND/NOT FOUND, "found" is that setting of the enclosing item check.
     *
     * @param bool $wanted
     * @return array<int, array{leaf: ConditionNode, item: string|null, found: bool|null}>
     */
    public function getFailingChecks(bool $wanted = true): array
    {
        return $this->failingChecks($wanted);
    }

    /**
     * Each distinct failing condition once; per-item failures list the items with their compared values:
     * "Category is one of Pants, Tees — no item matches: MP01 (Pants), 24-UG06 (Gear)".
     *
     * @param bool $wanted
     * @return string[]
     */
    public function getFailureSummaries(bool $wanted = true): array
    {
        $groups = [];
        foreach ($this->getFailingChecks($wanted) as ['leaf' => $leaf, 'item' => $item, 'found' => $found]) {
            $text = $leaf->getText();
            $groups[$text] ??= ['found' => $found, 'items' => []];
            $groups[$text]['found'] ??= $found;
            if ($item !== null) {
                $labels = [...$groups[$text]['items'][$item] ?? [], ...$leaf->getActualLabels() ?? []];
                $labels = array_values(array_unique($labels));
                natcasesort($labels);
                $groups[$text]['items'][$item] = array_values($labels);
            }
        }

        $summaries = [];
        foreach ($groups as $text => ['found' => $found, 'items' => $items]) {
            if ($items === []) {
                $summaries[] = $text;
                continue;
            }
            $list = [];
            foreach ($items as $item => $labels) {
                $list[] = $labels === [] ? $item : sprintf('%s (%s)', $item, implode(', ', $labels));
            }
            $summaries[] = (string)match ($found) {
                true => __('%1 — no item matches: %2', $text, implode(', ', $list)),
                false => __('%1 — found: %2', $text, implode(', ', $list)),
                default => __('%1 — %2', $text, implode(', ', $list)),
            };
        }

        return $summaries;
    }

    /**
     * Recursive part of getFailingChecks().
     *
     * @param bool $wanted
     * @param string|null $item
     * @param bool|null $found
     * @return array<int, array{leaf: ConditionNode, item: string|null, found: bool|null}>
     */
    private function failingChecks(bool $wanted, ?string $item = null, ?bool $found = null): array
    {
        if (!$this->evaluated || $this->result === $wanted) {
            return [];
        }
        $item = $this->item ?? $item;
        if ($this->children === [] || ($this->expected !== null && !is_bool($this->expected))) {
            return [['leaf' => $this, 'item' => $item, 'found' => $found]];
        }
        // A group that wrongly matched is caused by its matching children, one that failed by the others.
        $childWanted = is_bool($this->expected) ? ($wanted ? $this->expected : !$this->expected) : $wanted;
        if ($this->item !== null && is_bool($this->expected)) {
            // Under a negated parent, a FOUND item check fails because the item matches.
            $found = $childWanted;
        }
        $checks = [];
        foreach ($this->children as $child) {
            array_push($checks, ...$child->failingChecks($childWanted, $item, $found));
        }

        return $checks;
    }

    /**
     * Labels as display text, bracketed for list values.
     *
     * @param string[]|null $labels
     * @param mixed $raw
     * @return string|null
     */
    private function joinLabels(?array $labels, mixed $raw): ?string
    {
        if ($labels === null) {
            return null;
        }
        $text = implode(', ', $labels);

        return is_array($raw) ? '[' . $text . ']' : $text;
    }

    /**
     * Copy with some constructor arguments replaced.
     *
     * @param array $changes constructor argument name => value
     * @return ConditionNode
     */
    private function copy(array $changes): ConditionNode
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * JSON-ready representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'description' => $this->description,
            'text' => $this->getText(),
            'item' => $this->item,
            'input_type' => $this->inputType,
            'aggregator' => $this->aggregator,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'expected_label' => $this->getExpectedLabel(),
            'actual_label' => $this->getActualLabel(),
            'result' => $this->result,
            'evaluated' => $this->evaluated,
            'note' => $this->note,
            'children' => array_map(static fn (ConditionNode $c): array => $c->toArray(), $this->children),
            'item_ids' => $this->itemIds,
            'member_actuals' => $this->memberActuals,
        ];
    }
}
