<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Rule;

use DmLab\PriceExplain\Model\Report\Stage;
use Magento\SalesRule\Model\Rule;

/**
 * Pre-filter outcome of one cart price rule: candidate, or the first failing stage.
 */
class Candidate
{
    /**
     * @param Rule $rule
     * @param string|null $stage Stage::* the rule failed at; null when it passed the pre-filter
     * @param string|null $reason
     */
    public function __construct(
        private readonly Rule $rule,
        private readonly ?string $stage = null,
        private readonly ?string $reason = null
    ) {
    }

    /**
     * Rule model.
     *
     * @return Rule
     */
    public function getRule(): Rule
    {
        return $this->rule;
    }

    /**
     * First failing stage.
     *
     * @return string|null
     */
    public function getStage(): ?string
    {
        return $this->stage;
    }

    /**
     * Why the stage failed.
     *
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Whether the rule passed the pre-filter and goes on to conditions.
     *
     * @return bool
     */
    public function isCandidate(): bool
    {
        return $this->stage === null;
    }

    /**
     * Whether the rule is not assigned to the quote's website.
     *
     * @return bool
     */
    public function isOtherWebsite(): bool
    {
        return $this->stage === Stage::WEBSITE;
    }
}
