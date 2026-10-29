<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Storefront;

use DmLab\PriceExplain\Model\Storefront\InvalidTokenException;
use DmLab\PriceExplain\Model\Storefront\TokenService;
use InvalidArgumentException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase
{
    /**
     * @var array<string, array{0: string, 1: int|null}>
     */
    private array $cache = [];

    /**
     * @var int
     */
    private int $now = 1_761_740_000;

    /**
     * @var string
     */
    private string $key = 'crypt-key';

    /**
     * @var array<string, bool>
     */
    private array $locks = [];

    private function service(): TokenService
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(
            fn (string $data): string => hash_hmac('sha256', $data, $this->key)
        );
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id, array $tags = [], $lifeTime = null): bool {
                $this->cache[$id] = [$data, $lifeTime];

                return true;
            }
        );
        $cache->method('load')->willReturnCallback(fn (string $id) => $this->cache[$id][0] ?? false);
        $cache->method('remove')->willReturnCallback(function (string $id): bool {
            unset($this->cache[$id]);

            return true;
        });
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(function (string $name): bool {
            if (isset($this->locks[$name])) {
                return false;
            }
            $this->locks[$name] = true;

            return true;
        });
        $lockManager->method('unlock')->willReturnCallback(function (string $name): bool {
            unset($this->locks[$name]);

            return true;
        });

        return new TokenService($encryptor, $cache, $dateTime, $lockManager);
    }

    public function testIssuedTokenIsConsumedOnce(): void
    {
        $service = $this->service();
        $token = $service->issue(TokenService::KIND_ORDER, 7, true, 2, 1);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[0-9a-f]{64}$/', $token);
        self::assertCount(1, $this->cache);
        self::assertSame(TokenService::TTL, array_values($this->cache)[0][1]);

        $payload = $service->consume($token, 2);
        self::assertSame('order', $payload->getKind());
        self::assertSame(7, $payload->getId());
        self::assertTrue($payload->isAllRules());
        self::assertSame(2, $payload->getStoreId());
        self::assertSame(1, $payload->getAdminUserId());
        self::assertSame($this->now + 60, $payload->getExpiresAt());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload->getNonce());
        self::assertSame([], $this->cache);

        $this->assertRejected(InvalidTokenException::REUSED, fn () => $service->consume($token, 2));
    }

    public function testTokenBeingConsumedConcurrentlyIsRejected(): void
    {
        $service = $this->service();
        $token = $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1);
        $nonceKey = array_key_first($this->cache);
        $this->locks[$nonceKey] = true;

        $this->assertRejected(InvalidTokenException::REUSED, fn () => $service->consume($token, 1));
        self::assertArrayHasKey($nonceKey, $this->cache, 'the request holding the lock still burns it');

        unset($this->locks[$nonceKey]);
        self::assertSame(14, $service->consume($token, 1)->getId());
        self::assertSame([], $this->locks, 'the lock is released');
    }

    public function testTokensHaveDistinctNonces(): void
    {
        $service = $this->service();

        self::assertNotSame(
            $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1),
            $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1)
        );
        self::assertCount(2, $this->cache);
    }

    public function testTokenIsValidUntilItsExpiry(): void
    {
        $service = $this->service();
        $token = $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1);
        $this->now += TokenService::TTL;

        self::assertSame(14, $service->consume($token, 1)->getId());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $service = $this->service();
        $token = $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1);
        $this->now += TokenService::TTL + 1;

        $this->assertRejected(InvalidTokenException::EXPIRED, fn () => $service->consume($token, 1));
    }

    public function testTokenOfAnotherStoreIsRejectedWithoutBurningIt(): void
    {
        $service = $this->service();
        $token = $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1);

        $this->assertRejected(InvalidTokenException::WRONG_STORE, fn () => $service->consume($token, 2));
        self::assertSame(14, $service->consume($token, 1)->getId());
    }

    public function testTokenSignedWithAnotherKeyIsRejected(): void
    {
        $service = $this->service();
        $token = $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1);
        $this->key = 'other-key';

        $this->assertRejected(InvalidTokenException::SIGNATURE, fn () => $service->consume($token, 1));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $service = $this->service();
        [$payload, $signature] = explode('.', $service->issue(TokenService::KIND_QUOTE, 14, false, 1, 1));
        $json = (string)base64_decode(strtr($payload, '-_', '+/'));
        $forged = rtrim(strtr(base64_encode(str_replace('"id":14', '"id":15', $json)), '+/', '-_'), '=');

        $this->assertRejected(
            InvalidTokenException::SIGNATURE,
            fn () => $service->consume($forged . '.' . $signature, 1)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'no separator' => ['abc'],
            'three parts' => ['a.b.c'],
            'bad signature' => ['eyJrIjoicXVvdGUifQ.' . str_repeat('0', 64)],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenIsRejected(string $token): void
    {
        $service = $this->service();

        $this->assertRejected(InvalidTokenException::SIGNATURE, fn () => $service->consume($token, 1));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPayloads(): array
    {
        $valid = ['k' => 'quote', 'id' => 14, 'r' => false, 's' => 1, 'u' => 1, 'e' => 1_761_740_060, 'n' => 'ab12'];

        return [
            'not json' => ['not json'],
            'json scalar' => ['14'],
            'unknown kind' => [json_encode(['k' => 'cart'] + $valid)],
            'string id' => [json_encode(['id' => '14'] + $valid)],
            'non-bool all rules' => [json_encode(['r' => 1] + $valid)],
            'string store' => [json_encode(['s' => '1'] + $valid)],
            'string admin user' => [json_encode(['u' => '1'] + $valid)],
            'missing expiry' => [json_encode(array_diff_key($valid, ['e' => true]))],
            'missing nonce' => [json_encode(array_diff_key($valid, ['n' => true]))],
            'non-hex nonce' => [json_encode(['n' => 'xyz'] + $valid)],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testSignedButInvalidPayloadIsRejected(string $json): void
    {
        $service = $this->service();
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $token = $payload . '.' . hash_hmac('sha256', $payload, $this->key);

        $this->assertRejected(InvalidTokenException::SIGNATURE, fn () => $service->consume($token, 1));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function invalidSubjects(): array
    {
        return [
            'unknown kind' => ['cart', 1],
            'zero id' => [TokenService::KIND_QUOTE, 0],
        ];
    }

    #[DataProvider('invalidSubjects')]
    public function testIssueRejectsInvalidSubject(string $kind, int $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->issue($kind, $id, false, 1, 1);
    }

    /**
     * @param string $reason
     * @param callable $consume
     */
    private function assertRejected(string $reason, callable $consume): void
    {
        try {
            $consume();
            self::fail('Token accepted.');
        } catch (InvalidTokenException $e) {
            self::assertSame($reason, $e->getReason());
            self::assertSame('Price explanation token rejected: ' . $reason . '.', $e->getMessage());
        }
    }
}
