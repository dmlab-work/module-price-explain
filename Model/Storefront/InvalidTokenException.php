<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Storefront;

use Magento\Framework\Exception\LocalizedException;

/**
 * A storefront explanation token was rejected.
 */
class InvalidTokenException extends LocalizedException
{
    public const SIGNATURE = 'invalid_signature';
    public const EXPIRED = 'expired';
    public const REUSED = 'reused';
    public const WRONG_STORE = 'wrong_store';

    /**
     * @var string
     */
    private string $reason;

    /**
     * @param string $reason one of the class constants
     */
    public function __construct(string $reason)
    {
        $this->reason = $reason;
        parent::__construct(__('Price explanation token rejected: %1.', $reason));
    }

    /**
     * Why the token was rejected.
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
