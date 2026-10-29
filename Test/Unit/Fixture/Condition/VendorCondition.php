<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Fixture\Condition;

use Magento\Framework\Model\AbstractModel;
use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * Third-party condition node with its own validation.
 */
class VendorCondition extends AbstractCondition
{
    /**
     * @var bool
     */
    public bool $verdict = true;

    /**
     * @var bool
     */
    public bool $brokenDescription = false;

    /**
     * @inheritdoc
     */
    public function validate(AbstractModel $model)
    {
        return $this->verdict;
    }

    /**
     * @inheritdoc
     */
    public function asString($format = '')
    {
        if ($this->brokenDescription) {
            throw new \RuntimeException('broken');
        }

        return 'Customer is a VIP';
    }
}
