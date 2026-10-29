<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Controller\Report;

use DmLab\PriceExplain\Api\ExplainerInterface;
use DmLab\PriceExplain\Controller\Report\Json;
use DmLab\PriceExplain\Model\Report\Report;
use DmLab\PriceExplain\Model\Storefront\InvalidTokenException;
use DmLab\PriceExplain\Model\Storefront\Origins;
use DmLab\PriceExplain\Model\Storefront\TokenPayload;
use DmLab\PriceExplain\Model\Storefront\TokenService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Translate\InlineInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class JsonTest extends TestCase
{
    /**
     * @var string
     */
    private string $method = 'POST';

    /**
     * @var string
     */
    private string $post = '';

    /**
     * @var string
     */
    private string $content = '';

    /**
     * @var string
     */
    private string $adminUrl = 'https://shop.test/';

    /**
     * @var Throwable|null
     */
    private ?Throwable $consumeError = null;

    /**
     * @var Throwable|null
     */
    private ?Throwable $explainError = null;

    /**
     * @var array{0: string, 1: int}|null
     */
    private ?array $consumed = null;

    /**
     * @var array{0: string, 1: int, 2: bool}|null
     */
    private ?array $explained = null;

    /**
     * @var TokenPayload|null
     */
    private ?TokenPayload $payload = null;

    /**
     * @var string[]
     */
    private array $logged = [];

    private function controller(): Json
    {
        $request = $this->createStub(Http::class);
        $request->method('isOptions')->willReturnCallback(fn (): bool => $this->method === 'OPTIONS');
        $request->method('isSecure')->willReturn(true);
        $request->method('getPost')->willReturnCallback(fn (string $name) => $name === 'token' ? $this->post : null);
        $request->method('getContent')->willReturnCallback(fn (): string => $this->content);

        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            fn (string $type) => $type === ResultFactory::TYPE_RAW
                ? new Raw()
                : new JsonResult($this->createStub(InlineInterface::class), new JsonSerializer())
        );

        $tokens = $this->createStub(TokenService::class);
        $tokens->method('consume')->willReturnCallback(function (string $token, int $storeId): TokenPayload {
            $this->consumed = [$token, $storeId];
            if ($this->consumeError !== null) {
                throw $this->consumeError;
            }

            return $this->payload ?? new TokenPayload('quote', 14, false, 1, 1, 0, 'ab');
        });

        $report = $this->createStub(Report::class);
        $report->method('toArray')->willReturn(['quote' => ['id' => 14]]);
        $explainer = $this->createStub(ExplainerInterface::class);
        $explain = function (string $kind) use ($report): callable {
            return function (int $id, bool $allRules) use ($kind, $report): Report {
                $this->explained = [$kind, $id, $allRules];
                if ($this->explainError !== null) {
                    throw $this->explainError;
                }

                return $report;
            };
        };
        $explainer->method('explainQuote')->willReturnCallback($explain('quote'));
        $explainer->method('explainOrderById')->willReturnCallback($explain('order'));

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('1');
        $store->method('getBaseUrl')->willReturnCallback(
            fn (string $type, ?bool $secure = null): string => $secure ? 'https://shop.test/' : 'http://shop.test/'
        );
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path): bool => $path === 'web/secure/use_in_adminhtml'
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $path === 'web/secure/base_url' ? $this->adminUrl : null
        );

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message): void {
            $this->logged[] = (string)$message;
        });

        return new Json(
            $request,
            $resultFactory,
            $tokens,
            $explainer,
            $storeManager,
            new Origins($scopeConfig),
            $logger
        );
    }

    /**
     * Render the controller result.
     *
     * @return array{code: int|null, headers: array<string, string>, body: string}
     */
    private function dispatch(): array
    {
        $rendered = ['code' => null, 'headers' => [], 'body' => ''];
        $response = $this->createStub(HttpInterface::class);
        $response->method('setHttpResponseCode')->willReturnCallback(
            function (int $code) use (&$rendered, $response) {
                $rendered['code'] = $code;

                return $response;
            }
        );
        $response->method('setHeader')->willReturnCallback(
            function (string $name, string $value) use (&$rendered, $response) {
                $rendered['headers'][$name] = $value;

                return $response;
            }
        );
        $response->method('setBody')->willReturnCallback(
            function (string $body) use (&$rendered, $response) {
                $rendered['body'] = $body;

                return $response;
            }
        );
        $this->controller()->execute()->renderResult($response);

        return $rendered;
    }

    public function testValidTokenReturnsTheReport(): void
    {
        $this->post = 'tok.en';

        $response = $this->dispatch();

        self::assertNull($response['code']);
        self::assertSame('{"quote":{"id":14}}', $response['body']);
        self::assertSame('application/json', $response['headers']['Content-Type']);
        self::assertSame('no-store', $response['headers']['Cache-Control']);
        self::assertSame(['tok.en', 1], $this->consumed);
        self::assertSame(['quote', 14, false], $this->explained);
    }

    public function testOrderTokenExplainsTheOrderWithAllRules(): void
    {
        $this->post = 'tok.en';
        $this->payload = new TokenPayload(TokenService::KIND_ORDER, 3, true, 1, 1, 0, 'ab');

        $this->dispatch();

        self::assertSame(['order', 3, true], $this->explained);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function tokenSources(): array
    {
        return [
            'plain-text body' => ['', "token=eyJr-_x.0a1\n", 'eyJr-_x.0a1'],
            'form field wins over the body' => ['form.tok', 'token=body.tok', 'form.tok'],
            'body with extra pairs' => ['', 'token=a.b&x=1', ''],
            'body with another field' => ['', 'nonce=a.b', ''],
            'no token' => ['', '', ''],
        ];
    }

    #[DataProvider('tokenSources')]
    public function testTokenSource(string $post, string $content, string $token): void
    {
        $this->post = $post;
        $this->content = $content;

        $this->dispatch();

        self::assertSame([$token, 1], $this->consumed);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedTokens(): array
    {
        return [
            'invalid signature' => [InvalidTokenException::SIGNATURE],
            'expired' => [InvalidTokenException::EXPIRED],
            'reused' => [InvalidTokenException::REUSED],
            'wrong store' => [InvalidTokenException::WRONG_STORE],
        ];
    }

    #[DataProvider('rejectedTokens')]
    public function testRejectedTokenIsForbiddenWithoutDetails(string $reason): void
    {
        $this->post = 'tok.en';
        $this->consumeError = new InvalidTokenException($reason);

        $response = $this->dispatch();

        self::assertSame(403, $response['code']);
        self::assertSame('{"error":"forbidden"}', $response['body']);
        self::assertSame('no-store', $response['headers']['Cache-Control']);
        self::assertNull($this->explained);
    }

    public function testMissingQuoteIsNotFoundWithoutDetails(): void
    {
        $this->post = 'tok.en';
        $this->explainError = new NoSuchEntityException(__('Quote with ID "%1" does not exist.', 14));

        $response = $this->dispatch();

        self::assertSame(404, $response['code']);
        self::assertSame('{"error":"not_found"}', $response['body']);
    }

    public function testExplainerErrorIsPassedToTheAdmin(): void
    {
        $this->post = 'tok.en';
        $this->explainError = new LocalizedException(__('Only one price explanation can run per request.'));

        $response = $this->dispatch();

        self::assertSame(422, $response['code']);
        self::assertSame(
            '{"error":"unprocessable","message":"Only one price explanation can run per request."}',
            $response['body']
        );
    }

    public function testUnexpectedErrorIsLoggedAndHidden(): void
    {
        $this->post = 'tok.en';
        $this->explainError = new RuntimeException('SQLSTATE secret');

        $response = $this->dispatch();

        self::assertSame(500, $response['code']);
        self::assertSame('{"error":"internal"}', $response['body']);
        self::assertSame(['Storefront price explanation failed.'], $this->logged);
    }

    public function testSameOriginGetsNoCorsHeaders(): void
    {
        $this->post = 'tok.en';

        $headers = $this->dispatch()['headers'];

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
        self::assertArrayNotHasKey('Vary', $headers);
    }

    public function testCrossOriginAllowsTheAdminOrigin(): void
    {
        $this->post = 'tok.en';
        $this->adminUrl = 'https://admin.shop.test/';

        $response = $this->dispatch();

        self::assertSame('https://admin.shop.test', $response['headers']['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response['headers']['Vary']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response['headers']);
    }

    public function testCrossOriginErrorsCarryCorsHeaders(): void
    {
        $this->post = 'tok.en';
        $this->adminUrl = 'https://admin.shop.test/';
        $this->consumeError = new InvalidTokenException(InvalidTokenException::REUSED);

        $response = $this->dispatch();

        self::assertSame(403, $response['code']);
        self::assertSame('https://admin.shop.test', $response['headers']['Access-Control-Allow-Origin']);
    }

    public function testCrossOriginPreflightIsAnswered(): void
    {
        $this->method = 'OPTIONS';
        $this->adminUrl = 'https://admin.shop.test/';

        $response = $this->dispatch();

        self::assertSame(204, $response['code']);
        self::assertSame('', $response['body']);
        self::assertSame('https://admin.shop.test', $response['headers']['Access-Control-Allow-Origin']);
        self::assertSame('POST', $response['headers']['Access-Control-Allow-Methods']);
        self::assertSame('Content-Type', $response['headers']['Access-Control-Allow-Headers']);
        self::assertSame('no-store', $response['headers']['Cache-Control']);
        self::assertNull($this->consumed);
    }

    public function testSameOriginPreflightGetsNoCorsHeaders(): void
    {
        $this->method = 'OPTIONS';

        $response = $this->dispatch();

        self::assertSame(204, $response['code']);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response['headers']);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response['headers']);
    }

    public function testCsrfValidationIsSkipped(): void
    {
        $controller = $this->controller();
        $request = $this->createStub(Http::class);

        self::assertTrue($controller->validateForCsrf($request));
        self::assertNull($controller->createCsrfValidationException($request));
    }
}
