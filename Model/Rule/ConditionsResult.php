<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use DmLab\PriceExplain\Model\Report\ConditionNode;
use DmLab\PriceExplain\Model\Report\Warning;

/**
 * Walked conditions tree of a rule plus the verdict of the rule's own validation.
 */
class ConditionsResult
{
    /**
     * @param ConditionNode $tree
     * @param bool $valid verdict of the rule's own validate($address)
     * @param Warning|null $warning set when the walked tree disagrees with the verdict
     */
    public function __construct(
        private readonly ConditionNode $tree,
        private readonly bool $valid,
        private readonly ?Warning $warning = null
    ) {
    }

    /**
     * Walked conditions tree.
     *
     * @return ConditionNode
     */
    public function getTree(): ConditionNode
    {
        return $this->tree;
    }

    /**
     * Whether the rule conditions pass.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Walker/core disagreement.
     *
     * @return Warning|null
     */
    public function getWarning(): ?Warning
    {
        return $this->warning;
    }
}
