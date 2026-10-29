<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Price;

use DmLab\PriceExplain\Model\Price\IndexChecker;
use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use Magento\Catalog\Model\Indexer\Product\Price\DimensionModeConfiguration;
use Magento\Catalog\Model\Indexer\Product\Price\ModeSwitcherConfiguration;
use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\Dimension;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\ScopeResolver\IndexScopeResolver;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\View\ChangelogTableNotExistsException;
use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Mview\ViewInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class IndexCheckerTest extends TestCase
{
    /**
     * @var array[] rows returned by the index select
     */
    private array $rows = [];

    /**
     * @var string[] tables passed to Select::from()
     */
    private array $tables = [];

    /**
     * @var array[] [condition, value] pairs passed to Select::where()
     */
    private array $wheres = [];

    /**
     * @var string dimension mode of the price index
     */
    private string $mode = DimensionModeConfiguration::DIMENSION_NONE;

    /**
     * @var array<string, array{status: string, scheduled: bool, version?: int|null, processed?: int}>
     */
    private array $indexers = [
        'catalog_product_price' => ['status' => 'valid', 'scheduled' => false],
        'catalogrule_rule' => ['status' => 'valid', 'scheduled' => false],
    ];

    /**
     * @var int IndexerRegistry::get() calls
     */
    private int $registryCalls = 0;

    public function testMatchingIndexProducesNoWarnings(): void
    {
        $this->rows = [['entity_id' => 10, 'final_price' => '46.8000', 'min_price' => '40.0000']];

        $warnings = $this->checker()->check(1, 0, [$this->item(10, base: 52.0, rule: 46.8, tier: 40.0)], 2);

        $this->assertSame([], $warnings);
        $this->assertSame(0, $this->registryCalls, 'indexer status is only read for a warning');
    }

    public function testComparisonUsesStorePrecision(): void
    {
        $this->rows = [['entity_id' => 10, 'final_price' => '46.8040', 'min_price' => '46.8040']];

        $this->assertSame([], $this->checker()->check(1, 0, [$this->item(10, base: 52.0, rule: 46.8)], 2));
        $this->assertCount(1, $this->checker()->check(1, 0, [$this->item(10, base: 52.0, rule: 46.8)], 3));
    }

    public function testIgnoresOptionsHigherQtyTiersAndInactiveSpecial(): void
    {
        $this->rows = [['entity_id' => 10, 'final_price' => '52.0000', 'min_price' => '45.0000']];
        $item = $this->item(
            10,
            base: 52.0,
            special: 30.0,
            specialActive: false,
            tier: 45.0,
            options: 5.0,
            tiers: [[5.0, 45.0]]
        );

        $this->assertSame([], $this->checker()->check(1, 0, [$item], 2));
    }

    public function testQtyOneTierPriceIsPartOfTheIndexedFinalPrice(): void
    {
        $this->rows = [['entity_id' => 10, 'final_price' => '40.0000', 'min_price' => '35.0000']];
        $item = $this->item(10, base: 50.0, tiers: [[1.0, 40.0], [5.0, 35.0]]);

        $this->assertSame([], $this->checker()->check(1, 3, [$item], 2));
    }

    public function testIndexIsQueriedForWebsiteGroupAndProducts(): void
    {
        $this->rows = [['entity_id' => 10, 'final_price' => '52.0000', 'min_price' => '52.0000']];

        $this->checker()->check(2, 3, [$this->item(10, base: 52.0), $this->item(11, base: 52.0, itemId: 2)], 2);

        $this->assertSame(
            [['website_id = ?', 2], ['customer_group_id = ?', 3], ['entity_id IN (?)', [10, 11]]],
            $this->wheres
        );
    }

    public function testMismatchWarnsWithIndexValuesAndIndexerStatus(): void
    {
        $this->rows = [['entity_id' => 10, 'final_price' => '52.0000', 'min_price' => '52.0000']];
        $this->indexers['catalogrule_rule'] = [
            'status' => 'invalid',
            'scheduled' => true,
            'version' => 12,
            'processed' => 9,
        ];

        $warnings = $this->checker()->check(1, 2, [$this->item(10, base: 52.0, rule: 46.8)], 2);

        $this->assertCount(1, $warnings);
        $warning = $warnings[0];
        $this->assertSame(IndexChecker::WARNING_MISMATCH, $warning->getCode());
        $this->assertStringContainsString(
            'indexed final price 52.00 differs from the computed price 46.80',
            $warning->getMessage()
        );
        $this->assertStringContainsString(
            'catalog_product_price valid, catalogrule_rule invalid (schedule, 3 in backlog)',
            $warning->getMessage()
        );
        $this->assertSame(
            [
                'item_id' => 1,
                'product_id' => 10,
                'table' => 'catalog_product_index_price',
                'expected' => '46.80',
                'indexers' => [
                    'catalog_product_price' => ['status' => 'valid', 'scheduled' => false, 'backlog' => null],
                    'catalogrule_rule' => ['status' => 'invalid', 'scheduled' => true, 'backlog' => 3],
                ],
                'final_price' => '52.00',
                'min_price' => '52.00',
            ],
            $warning->getContext()
        );
    }

    public function testMissingIndexRowWarns(): void
    {
        $this->indexers['catalog_product_price'] = ['status' => 'valid', 'scheduled' => true, 'version' => null];

        $warnings = $this->checker()->check(1, 0, [$this->item(10, base: 52.0)], 2);

        $this->assertCount(1, $warnings);
        $this->assertSame(IndexChecker::WARNING_MISSING, $warnings[0]->getCode());
        $this->assertStringContainsString('no price index row for product 10', $warnings[0]->getMessage());
        $this->assertStringContainsString('catalog_product_price valid (schedule)', $warnings[0]->getMessage());
        $this->assertNull($warnings[0]->getContext()['indexers']['catalog_product_price']['backlog']);
        $this->assertArrayNotHasKey('final_price', $warnings[0]->getContext());
    }

    public function testIndexerStatusIsReadOncePerCheck(): void
    {
        $items = [$this->item(10, base: 52.0, itemId: 1), $this->item(11, base: 9.0, itemId: 2)];

        $warnings = $this->checker()->check(1, 0, $items, 2);

        $this->assertCount(2, $warnings);
        $this->assertSame(2, $this->registryCalls);
    }

    public function testDimensionModeResolvesDimensionTable(): void
    {
        $this->mode = DimensionModeConfiguration::DIMENSION_WEBSITE_AND_CUSTOMER_GROUP;
        $this->rows = [['entity_id' => 10, 'final_price' => '52.0000', 'min_price' => '52.0000']];

        $this->assertSame([], $this->checker()->check(2, 3, [$this->item(10, base: 52.0)], 2));
        $this->assertSame(['catalog_product_index_price_cg3_ws2'], $this->tables);
    }

    public function testCustomerGroupModeResolvesGroupTable(): void
    {
        $this->mode = DimensionModeConfiguration::DIMENSION_CUSTOMER_GROUP;

        $warnings = $this->checker()->check(2, 3, [$this->item(10, base: 52.0)], 2);

        $this->assertSame('catalog_product_index_price_cg3', $warnings[0]->getContext()['table']);
    }

    public function testItemsWithoutLayersAreSkippedWithoutQuery(): void
    {
        $bundle = new ItemPrice(1, 'BUNDLE', 'Bundle', 1.0, 10, [], null, 100.0);

        $this->assertSame([], $this->checker()->check(1, 0, [$bundle], 2));
        $this->assertSame([], $this->checker()->check(1, 0, [], 2));
        $this->assertSame([], $this->tables);
    }

    /**
     * Item with the given layers; $tiers are [qty, price] pairs in the tier layer details.
     */
    private function item(
        int $productId,
        float $base,
        ?float $special = null,
        bool $specialActive = true,
        ?float $rule = null,
        ?float $tier = null,
        ?float $options = null,
        int $itemId = 1,
        array $tiers = []
    ): ItemPrice {
        $details = [
            'qty' => 1.0,
            'tiers' => array_map(
                static fn (array $t): array => ['qty' => $t[0], 'price' => $t[1], 'customer_group' => 32000],
                $tiers
            ),
        ];
        $layers = [
            new PriceLayer(PriceLayer::CODE_BASE, $base),
            new PriceLayer(PriceLayer::CODE_SPECIAL, $special, $special !== null && $specialActive),
            new PriceLayer(PriceLayer::CODE_TIER, $tier, $tier !== null, false, $details),
            new PriceLayer(PriceLayer::CODE_CATALOG_RULE, $rule, $rule !== null),
        ];
        if ($options !== null) {
            $layers[] = new PriceLayer(PriceLayer::CODE_OPTIONS, $options);
        }

        return new ItemPrice($itemId, 'SKU-' . $productId, 'Product', 1.0, $productId, $layers, $base, $base);
    }

    private function checker(): IndexChecker
    {
        $this->tables = [];
        $this->wheres = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(function (string $table) use ($select): Select {
            $this->tables[] = $table;
            return $select;
        });
        $select->method('where')->willReturnCallback(function (string $cond, mixed $value) use ($select): Select {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(fn (): array => $this->rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(ModeSwitcherConfiguration::XML_PATH_PRICE_DIMENSIONS_MODE)
            ->willReturn($this->mode);
        $resolver = new PriceTableResolver(
            new IndexScopeResolver($resource, $this->createMock(ScopeResolverInterface::class)),
            new DimensionModeConfiguration($scopeConfig)
        );

        $dimensionFactory = $this->createMock(DimensionFactory::class);
        $dimensionFactory->method('create')
            ->willReturnCallback(static fn (string $name, string $value): Dimension => new Dimension($name, $value));

        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->willReturnCallback(function (string $id): IndexerInterface {
            $this->registryCalls++;
            return $this->indexer($this->indexers[$id]);
        });

        return new IndexChecker($resource, $resolver, $dimensionFactory, $registry);
    }

    /**
     * @param array{status: string, scheduled: bool, version?: int|null, processed?: int} $config
     */
    private function indexer(array $config): IndexerInterface
    {
        $changelog = $this->createMock(ChangelogInterface::class);
        if (($config['version'] ?? null) === null) {
            $changelog->method('getVersion')->willThrowException(new ChangelogTableNotExistsException(__('missing')));
        } else {
            $changelog->method('getVersion')->willReturn($config['version']);
        }
        $state = $this->createMock(StateInterface::class);
        $state->method('getVersionId')->willReturn($config['processed'] ?? 0);
        $view = $this->createMock(ViewInterface::class);
        $view->method('getChangelog')->willReturn($changelog);
        $view->method('getState')->willReturn($state);

        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getStatus')->willReturn($config['status']);
        $indexer->method('isScheduled')->willReturn($config['scheduled']);
        $indexer->method('getView')->willReturn($view);

        return $indexer;
    }
}
