<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Plugin\Rule;

use DmLab\PriceExplain\Model\Trace\Tracer;
use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * Records the actual value each rule condition compares while the condition walker runs.
 */
class CaptureValidatedValuePlugin
{
    /**
     * @param Tracer $tracer
     */
    public function __construct(private readonly Tracer $tracer)
    {
    }

    /**
     * Record the compared value; arguments are left untouched.
     *
     * @param AbstractCondition $subject
     * @param mixed $validatedValue
     * @return null
     */
    public function beforeValidateAttribute(AbstractCondition $subject, $validatedValue)
    {
        if ($this->tracer->isCapturing()) {
            $this->tracer->recordValidatedValue($subject, $validatedValue);
        }

        return null;
    }
}
