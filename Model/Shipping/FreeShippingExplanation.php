<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Shipping;

use DmLab\PriceExplain\Model\Report\FreeShipping;
use DmLab\PriceExplain\Model\Report\RuleVerdict;
use DmLab\PriceExplain\Model\Report\Warning;

/**
 * Free-shipping section plus the rule verdicts annotated with its outcome.
 */
class FreeShippingExplanation
{
    /**
     * @param FreeShipping|null $freeShipping null when nothing grants free shipping
     * @param RuleVerdict[] $verdicts
     * @param Warning[] $warnings
     */
    public function __construct(
        private readonly ?FreeShipping $freeShipping,
        private readonly array $verdicts,
        private readonly array $warnings = []
    ) {
    }

    /**
     * Free-shipping section.
     *
     * @return FreeShipping|null
     */
    public function getFreeShipping(): ?FreeShipping
    {
        return $this->freeShipping;
    }

    /**
     * Rule verdicts; free-shipping rules carry the outcome.
     *
     * @return RuleVerdict[]
     */
    public function getVerdicts(): array
    {
        return $this->verdicts;
    }

    /**
     * Contradiction warnings.
     *
     * @return Warning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
