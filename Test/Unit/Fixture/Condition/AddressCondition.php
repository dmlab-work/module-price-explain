<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Fixture\Condition;

class AddressCondition extends \Magento\SalesRule\Model\Rule\Condition\Address
{
    use CapturesValidatedValue;
}
