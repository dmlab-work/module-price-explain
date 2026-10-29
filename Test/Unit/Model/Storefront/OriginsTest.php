<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Storefront;

use DmLab\PriceExplain\Model\Storefront\Origins;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OriginsTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function adminConfigs(): array
    {
        $base = [
            'web/unsecure/base_url' => 'http://shop.test/',
            'web/secure/base_url' => 'https://shop.test/',
        ];

        return [
            'secure admin' => [$base + ['web/secure/use_in_adminhtml' => '1'], 'https://shop.test'],
            'unsecure admin' => [$base + ['web/secure/use_in_adminhtml' => '0'], 'http://shop.test'],
            'custom admin url' => [
                $base + ['admin/url/use_custom' => '1', 'admin/url/custom' => 'https://Admin.shop.test:8443/x/'],
                'https://admin.shop.test:8443',
            ],
            'no base url' => [[], null],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param string|null $expected
     */
    #[DataProvider('adminConfigs')]
    public function testAdminOrigin(array $config, ?string $expected): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $config[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (string $path): bool => !empty($config[$path]));

        self::assertSame($expected, (new Origins($scopeConfig))->admin());
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function urls(): array
    {
        return [
            'path and query' => ['https://shop.test/en/?a=1', 'https://shop.test'],
            'port' => ['http://SHOP.test:8080/', 'http://shop.test:8080'],
            'no path' => ['https://shop.test', 'https://shop.test'],
            'placeholder' => ['{{unsecure_base_url}}', null],
            'relative' => ['/admin', null],
        ];
    }

    #[DataProvider('urls')]
    public function testOriginOfUrl(string $url, ?string $expected): void
    {
        self::assertSame($expected, (new Origins($this->createStub(ScopeConfigInterface::class)))->of($url));
    }
}
