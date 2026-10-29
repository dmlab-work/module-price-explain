<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Fixture\Condition;

use DmLab\PriceExplain\Plugin\Rule\CaptureValidatedValuePlugin;

/**
 * Stands in for the generated interceptor: runs the capture plugin before validateAttribute().
 */
trait CapturesValidatedValue
{
    /**
     * @var CaptureValidatedValuePlugin|null
     */
    public ?CaptureValidatedValuePlugin $capture = null;

    /**
     * @inheritdoc
     */
    public function validateAttribute($validatedValue)
    {
        $this->capture?->beforeValidateAttribute($this, $validatedValue);

        return parent::validateAttribute($validatedValue);
    }
}
