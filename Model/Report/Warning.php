<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Something the report could not fully explain or that the reader should double-check.
 */
class Warning
{
    /**
     * @param string $code machine-readable code (rule_mismatch, index_mismatch, ...)
     * @param string $message
     * @param array $context
     */
    public function __construct(
        private readonly string $code,
        private readonly string $message,
        private readonly array $context = []
    ) {
    }

    /**
     * Warning code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Human-readable message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Related IDs and values.
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * JSON-ready representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
