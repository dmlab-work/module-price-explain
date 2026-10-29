<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;

/**
 * Replaces category IDs in condition labels with store-scoped names, one lookup for all verdicts of a report.
 */
class CategoryLabels
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param ValueLabels $valueLabels
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ValueLabels $valueLabels
    ) {
    }

    /**
     * Verdicts with category names in their condition and actions trees; unknown IDs stay `#id`.
     *
     * @param RuleVerdict[] $verdicts
     * @param int $storeId
     * @return RuleVerdict[]
     */
    public function apply(array $verdicts, int $storeId): array
    {
        $ids = [];
        foreach ($verdicts as $verdict) {
            foreach ($verdict->getConditionTrees() as $tree) {
                foreach ($tree->flatten() as $node) {
                    if ($node->getInputType() === ValueLabels::INPUT_CATEGORY) {
                        array_push($ids, ...$this->ids($node->getExpected()), ...$this->ids($node->getActual()));
                        foreach ($node->getMemberActuals() ?? [] as $member) {
                            array_push($ids, ...$this->ids($member['actual']));
                        }
                    }
                }
            }
        }
        if ($ids === []) {
            return $verdicts;
        }
        $names = $this->names(array_values(array_unique($ids)), $storeId);
        $label = static fn (string $id): string => $names[(int)$id] ?? '#' . $id;
        $relabel = function (ConditionNode $node) use ($label): ConditionNode {
            if ($node->getInputType() !== ValueLabels::INPUT_CATEGORY) {
                return $node;
            }
            $node = $node->withLabels(
                $this->valueLabels->format($node->getExpected(), $label, false),
                $this->valueLabels->format($node->getActual(), $label, true)
            );
            if ($node->getMemberActuals() === null) {
                return $node;
            }
            $members = [];
            $actualLabels = [];
            foreach ($node->getMemberActuals() as $member) {
                $actualLabels[] = $this->valueLabels->format($member['actual'], $label, true);
                $member['actual_label'] = $this->valueLabels->join(end($actualLabels), $member['actual']);
                $members[] = $member;
            }

            return $node->withMemberActuals($node->getResult(), $members, $this->valueLabels->merge($actualLabels));
        };

        return array_map(
            static fn (RuleVerdict $verdict): RuleVerdict => $verdict->mapConditionTrees(
                static fn (ConditionNode $tree): ConditionNode => $tree->map($relabel)
            ),
            $verdicts
        );
    }

    /**
     * Positive integer IDs of a condition value.
     *
     * @param mixed $value
     * @return int[]
     */
    private function ids(mixed $value): array
    {
        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $id) {
            if (is_scalar($id) && ctype_digit((string)$id) && (int)$id > 0) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    /**
     * Category names in the store scope by ID; names shared by several categories get their ID appended.
     *
     * @param int[] $ids
     * @param int $storeId
     * @return array<int, string>
     */
    private function names(array $ids, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect('name')
            ->addIdFilter($ids);
        $names = [];
        foreach ($collection as $category) {
            $name = (string)$category->getName();
            $names[(int)$category->getId()] = $name !== '' ? $name : '#' . $category->getId();
        }
        $counts = array_count_values($names);
        foreach ($names as $id => $name) {
            if ($counts[$name] > 1) {
                $names[$id] = sprintf('%s (#%d)', $name, $id);
            }
        }

        return $names;
    }
}
