<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Storefront;

use InvalidArgumentException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Signed single-use tokens that let the admin run an explanation on the storefront.
 *
 * Token = base64url(payload JSON) . "." . HMAC-SHA256 hex (crypt key); the nonce lives in cache until consumed.
 */
class TokenService
{
    public const KIND_QUOTE = 'quote';
    public const KIND_ORDER = 'order';
    public const TTL = 60;
    private const NONCE_PREFIX = 'dmlab_price_explain_nonce_';

    /**
     * @param EncryptorInterface $encryptor
     * @param CacheInterface $cache
     * @param DateTime $dateTime
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache,
        private readonly DateTime $dateTime,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Issue a token for one explanation of a quote or order in a store view.
     *
     * @param string $kind
     * @param int $id
     * @param bool $allRules
     * @param int $storeId
     * @param int $adminUserId
     * @return string
     * @throws InvalidArgumentException
     */
    public function issue(string $kind, int $id, bool $allRules, int $storeId, int $adminUserId): string
    {
        if (!in_array($kind, [self::KIND_QUOTE, self::KIND_ORDER], true) || $id <= 0) {
            throw new InvalidArgumentException(sprintf('Cannot issue a token for %s #%d.', $kind, $id));
        }
        $nonce = bin2hex(random_bytes(16));
        $this->cache->save('1', self::NONCE_PREFIX . $nonce, [], self::TTL);
        $payload = $this->encode(json_encode([
            'k' => $kind,
            'id' => $id,
            'r' => $allRules,
            's' => $storeId,
            'u' => $adminUserId,
            'e' => $this->now() + self::TTL,
            'n' => $nonce,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->encryptor->hash($payload);
    }

    /**
     * Verify a token for the current store and burn its nonce.
     *
     * @param string $token
     * @param int $storeId current store view
     * @return TokenPayload
     * @throws InvalidTokenException
     */
    public function consume(string $token, int $storeId): TokenPayload
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals($this->encryptor->hash($parts[0]), $parts[1])) {
            throw new InvalidTokenException(InvalidTokenException::SIGNATURE);
        }
        $payload = $this->decode($parts[0]);
        if ($payload->getExpiresAt() < $this->now()) {
            throw new InvalidTokenException(InvalidTokenException::EXPIRED);
        }
        if ($payload->getStoreId() !== $storeId) {
            throw new InvalidTokenException(InvalidTokenException::WRONG_STORE);
        }
        $key = self::NONCE_PREFIX . $payload->getNonce();
        // The lock makes check-and-burn atomic: a concurrent request with the same token is rejected.
        if (!$this->lockManager->lock($key, 0)) {
            throw new InvalidTokenException(InvalidTokenException::REUSED);
        }
        try {
            if ($this->cache->load($key) === false) {
                throw new InvalidTokenException(InvalidTokenException::REUSED);
            }
            $this->cache->remove($key);
        } finally {
            $this->lockManager->unlock($key);
        }

        return $payload;
    }

    /**
     * Payload of a signed token part.
     *
     * @param string $encoded
     * @return TokenPayload
     * @throws InvalidTokenException
     */
    private function decode(string $encoded): TokenPayload
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)
            || !in_array($data['k'] ?? null, [self::KIND_QUOTE, self::KIND_ORDER], true)
            || !is_int($data['id'] ?? null) || !is_bool($data['r'] ?? null) || !is_int($data['s'] ?? null)
            || !is_int($data['u'] ?? null) || !is_int($data['e'] ?? null) || !is_string($data['n'] ?? null)
            || !ctype_xdigit($data['n'])
        ) {
            throw new InvalidTokenException(InvalidTokenException::SIGNATURE);
        }

        return new TokenPayload($data['k'], $data['id'], $data['r'], $data['s'], $data['u'], $data['e'], $data['n']);
    }

    /**
     * Base64url without padding.
     *
     * @param string $value
     * @return string
     */
    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Current Unix timestamp.
     *
     * @return int
     */
    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }
}
