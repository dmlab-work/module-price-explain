<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Storefront;

/**
 * Verified content of a storefront explanation token.
 */
class TokenPayload
{
    /**
     * @param string $kind TokenService::KIND_QUOTE or KIND_ORDER
     * @param int $id quote ID or order entity ID
     * @param bool $allRules
     * @param int $storeId
     * @param int $adminUserId
     * @param int $expiresAt Unix timestamp
     * @param string $nonce
     */
    public function __construct(
        private readonly string $kind,
        private readonly int $id,
        private readonly bool $allRules,
        private readonly int $storeId,
        private readonly int $adminUserId,
        private readonly int $expiresAt,
        private readonly string $nonce
    ) {
    }

    /**
     * Quote or order.
     *
     * @return string
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Quote ID or order entity ID.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Whether rules that cannot apply to the quote are reported too.
     *
     * @return bool
     */
    public function isAllRules(): bool
    {
        return $this->allRules;
    }

    /**
     * Store view the token is valid for.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * Admin user who issued the token.
     *
     * @return int
     */
    public function getAdminUserId(): int
    {
        return $this->adminUserId;
    }

    /**
     * Expiry as a Unix timestamp.
     *
     * @return int
     */
    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Single-use nonce.
     *
     * @return string
     */
    public function getNonce(): string
    {
        return $this->nonce;
    }
}
