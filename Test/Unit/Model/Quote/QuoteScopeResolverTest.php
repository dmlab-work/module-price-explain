<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Quote;

use DmLab\PriceExplain\Model\Quote\QuoteScopeResolver;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class QuoteScopeResolverTest extends TestCase
{
    public function testResolvesNamesAndCustomer(): void
    {
        $scope = $this->resolver('General')->resolve($this->quote([
            'customer_id' => 5,
            'customer_group_id' => 1,
            'customer_email' => 'roni@example.com',
            'customer_firstname' => 'Veronica',
            'customer_lastname' => 'Costello',
        ]));

        self::assertSame('Default Store View', $scope->getStoreName());
        self::assertSame('Main Website', $scope->getWebsiteName());
        self::assertSame('General', $scope->getCustomerGroupName());
        self::assertSame(5, $scope->getCustomerId());
        self::assertFalse($scope->isGuest());
        self::assertSame('roni@example.com', $scope->getCustomerEmail());
        self::assertSame('Veronica Costello', $scope->getCustomerName());
    }

    public function testGuestWithoutNameOrEmail(): void
    {
        $scope = $this->resolver('NOT LOGGED IN')->resolve($this->quote(['customer_group_id' => 0]));

        self::assertTrue($scope->isGuest());
        self::assertNull($scope->getCustomerId());
        self::assertNull($scope->getCustomerEmail());
        self::assertNull($scope->getCustomerName());
        self::assertSame('NOT LOGGED IN', $scope->getCustomerGroupName());
    }

    public function testMissingGroupAndWebsiteGiveNullNames(): void
    {
        $scope = $this->resolver(null)->resolve($this->quote(['customer_group_id' => 99], false));

        self::assertNull($scope->getCustomerGroupName());
        self::assertNull($scope->getWebsiteName());
        self::assertSame('Default Store View', $scope->getStoreName());
    }

    private function resolver(?string $groupCode): QuoteScopeResolver
    {
        $repository = $this->createStub(GroupRepositoryInterface::class);
        if ($groupCode === null) {
            $repository->method('getById')->willThrowException(new NoSuchEntityException());
        } else {
            $group = $this->createStub(GroupInterface::class);
            $group->method('getCode')->willReturn($groupCode);
            $repository->method('getById')->willReturn($group);
        }

        return new QuoteScopeResolver($repository);
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $withWebsite
     * @return Quote
     */
    private function quote(array $data, bool $withWebsite = true): Quote
    {
        $website = $this->createStub(Website::class);
        $website->method('getName')->willReturn('Main Website');
        $store = $this->createStub(Store::class);
        $store->method('getName')->willReturn('Default Store View');
        $store->method('getWebsite')->willReturn($withWebsite ? $website : false);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStore'])
            ->getMock();
        $quote->method('getStore')->willReturn($store);
        $quote->setData($data);

        return $quote;
    }
}
