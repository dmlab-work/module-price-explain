<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Warning;

/**
 * Cart rules section of the report: one verdict per rule plus walker/core disagreements.
 */
class RulesExplanation
{
    /**
     * @param RuleVerdict[] $verdicts
     * @param Warning[] $warnings
     */
    public function __construct(
        private readonly array $verdicts,
        private readonly array $warnings
    ) {
    }

    /**
     * Rule verdicts in processing order.
     *
     * @return RuleVerdict[]
     */
    public function getVerdicts(): array
    {
        return $this->verdicts;
    }

    /**
     * Condition mismatches and core reconciliation warnings.
     *
     * @return Warning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
