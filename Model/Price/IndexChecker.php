<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Price;

use DmLab\PriceExplain\Model\Report\ItemPrice;
use DmLab\PriceExplain\Model\Report\PriceLayer;
use DmLab\PriceExplain\Model\Report\Warning;
use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Customer\Model\Indexer\CustomerGroupDimensionProvider;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\View\ChangelogTableNotExistsException;
use Magento\Framework\Mview\ViewInterface;
use Magento\Store\Model\Indexer\WebsiteDimensionProvider;

/**
 * Compares computed prices with catalog_product_index_price, which feeds listings, sorting and layered navigation.
 */
class IndexChecker
{
    public const WARNING_MISMATCH = 'index_mismatch';
    public const WARNING_MISSING = 'index_missing';
    public const INDEX = 'catalog_product_index_price';
    public const INDEXERS = ['catalog_product_price', 'catalogrule_rule'];

    /**
     * Layers that make up the indexed final_price besides qty-1 tier prices; options are not indexed.
     */
    private const INDEXED_LAYERS = [
        PriceLayer::CODE_BASE,
        PriceLayer::CODE_SPECIAL,
        PriceLayer::CODE_CATALOG_RULE,
    ];

    /**
     * @param ResourceConnection $resourceConnection
     * @param PriceTableResolver $priceTableResolver
     * @param DimensionFactory $dimensionFactory
     * @param IndexerRegistry $indexerRegistry
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PriceTableResolver $priceTableResolver,
        private readonly DimensionFactory $dimensionFactory,
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    /**
     * Warnings for items whose indexed final price is missing or differs from the computed one.
     *
     * @param int $websiteId
     * @param int $customerGroupId
     * @param ItemPrice[] $items
     * @param int $precision store price precision
     * @return Warning[]
     */
    public function check(int $websiteId, int $customerGroupId, array $items, int $precision): array
    {
        $expected = [];
        foreach ($items as $item) {
            $value = $this->expectedFinal($item);
            if ($value !== null) {
                $expected[$item->getItemId()] = [$item, $value];
            }
        }
        if (!$expected) {
            return [];
        }

        $table = $this->table($websiteId, $customerGroupId);
        $productIds = array_unique(array_map(static fn (array $e): int => $e[0]->getProductId(), $expected));
        $rows = $this->rows($table, $websiteId, $customerGroupId, $productIds);

        $warnings = [];
        $indexers = null;
        foreach ($expected as [$item, $value]) {
            $row = $rows[$item->getProductId()] ?? null;
            if ($row !== null && round((float)$row['final_price'], $precision) == round($value, $precision)) {
                continue;
            }
            $indexers ??= $this->indexerStatus();
            $warnings[] = $this->warning($item, $value, $row, $table, $indexers, $precision);
        }

        return $warnings;
    }

    /**
     * Minimum of the active indexed layers and qty-1 tier prices (as BaseFinalPrice); null when not recomputed.
     *
     * @param ItemPrice $item
     * @return float|null
     */
    private function expectedFinal(ItemPrice $item): ?float
    {
        $prices = [];
        foreach ($item->getLayers() as $layer) {
            if ($layer->getCode() === PriceLayer::CODE_TIER) {
                foreach ($layer->getDetails()['tiers'] ?? [] as $tier) {
                    if ((float)$tier['qty'] == 1.0) {
                        $prices[] = (float)$tier['price'];
                    }
                }
            } elseif (in_array($layer->getCode(), self::INDEXED_LAYERS, true)
                && $layer->isActive() && $layer->getValue() !== null
            ) {
                $prices[] = $layer->getValue();
            }
        }

        return $prices === [] ? null : min($prices);
    }

    /**
     * Price index table of the dimension the website/group falls into.
     *
     * @param int $websiteId
     * @param int $customerGroupId
     * @return string
     */
    private function table(int $websiteId, int $customerGroupId): string
    {
        return $this->priceTableResolver->resolve(self::INDEX, [
            $this->dimensionFactory->create(WebsiteDimensionProvider::DIMENSION_NAME, (string)$websiteId),
            $this->dimensionFactory->create(CustomerGroupDimensionProvider::DIMENSION_NAME, (string)$customerGroupId),
        ]);
    }

    /**
     * Index rows keyed by product ID.
     *
     * @param string $table
     * @param int $websiteId
     * @param int $customerGroupId
     * @param int[] $productIds
     * @return array<int, array>
     */
    private function rows(string $table, int $websiteId, int $customerGroupId, array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($table, ['entity_id', 'final_price', 'min_price'])
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', $customerGroupId)
            ->where('entity_id IN (?)', array_values($productIds));
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int)$row['entity_id']] = $row;
        }

        return $rows;
    }

    /**
     * Status, mode and changelog backlog of the indexers that write the price index.
     *
     * @return array<string, array{status: string, scheduled: bool, backlog: int|null}>
     */
    private function indexerStatus(): array
    {
        $status = [];
        foreach (self::INDEXERS as $indexerId) {
            $indexer = $this->indexerRegistry->get($indexerId);
            $scheduled = (bool)$indexer->isScheduled();
            $status[$indexerId] = [
                'status' => (string)$indexer->getStatus(),
                'scheduled' => $scheduled,
                'backlog' => $scheduled ? $this->backlog($indexer->getView()) : null,
            ];
        }

        return $status;
    }

    /**
     * Changelog versions not yet processed by the view; null when the changelog table is missing.
     *
     * @param ViewInterface $view
     * @return int|null
     */
    private function backlog(ViewInterface $view): ?int
    {
        try {
            $version = (int)$view->getChangelog()->getVersion();
        } catch (ChangelogTableNotExistsException) {
            return null;
        }

        return max(0, $version - (int)$view->getState()->getVersionId());
    }

    /**
     * Mismatch or missing-row warning.
     *
     * @param ItemPrice $item
     * @param float $expected
     * @param array|null $row
     * @param string $table
     * @param array $indexers
     * @param int $precision
     * @return Warning
     */
    private function warning(
        ItemPrice $item,
        float $expected,
        ?array $row,
        string $table,
        array $indexers,
        int $precision
    ): Warning {
        $expectedText = number_format($expected, $precision, '.', '');
        $context = [
            'item_id' => $item->getItemId(),
            'product_id' => $item->getProductId(),
            'table' => $table,
            'expected' => $expectedText,
            'indexers' => $indexers,
        ];
        if ($row === null) {
            return new Warning(
                self::WARNING_MISSING,
                (string)__(
                    'Item %1: no price index row for product %2 in %3; listings may hide or misprice it. '
                    . 'Indexers: %4.',
                    $item->getLabel(),
                    $item->getProductId(),
                    $table,
                    $this->describe($indexers)
                ),
                $context
            );
        }

        $indexed = number_format((float)$row['final_price'], $precision, '.', '');

        return new Warning(
            self::WARNING_MISMATCH,
            (string)__(
                'Item %1: indexed final price %2 differs from the computed price %3; '
                . 'listings show the indexed price. Indexers: %4.',
                $item->getLabel(),
                $indexed,
                $expectedText,
                $this->describe($indexers)
            ),
            $context + [
                'final_price' => $indexed,
                'min_price' => number_format((float)$row['min_price'], $precision, '.', ''),
            ]
        );
    }

    /**
     * One-line indexer summary: "catalog_product_price valid, catalogrule_rule invalid (schedule, 3 in backlog)".
     *
     * @param array $indexers
     * @return string
     */
    private function describe(array $indexers): string
    {
        $parts = [];
        foreach ($indexers as $id => $info) {
            $part = $id . ' ' . $info['status'];
            if ($info['scheduled']) {
                $part .= $info['backlog'] === null
                    ? ' (schedule)'
                    : ' (schedule, ' . $info['backlog'] . ' in backlog)';
            }
            $parts[] = $part;
        }

        return implode(', ', $parts);
    }
}
