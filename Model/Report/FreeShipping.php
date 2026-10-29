<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Report;

/**
 * Free-shipping section: who granted it, which flags core set and whether the selected carrier honours them.
 */
class FreeShipping
{
    use FormatsAmounts;

    public const MODE_ITEMS = 'items';
    public const MODE_ADDRESS = 'address';
    /** SalesRule simple_free_shipping values (OfflineShipping\Model\SalesRule\Rule::FREE_SHIPPING_*). */
    public const RULE_MODES = [1 => self::MODE_ITEMS, 2 => self::MODE_ADDRESS];

    public const SEMANTICS_ITEMS = 'items_only';
    public const SEMANTICS_ADDRESS = 'address_flag';
    /** Table Rate: flagged units minus a numeric flag value per item, the address flag as the item fallback. */
    public const SEMANTICS_QTY = 'free_qty';
    public const SEMANTICS_UNKNOWN = 'unknown';

    public const EFFECTIVE = 'effective';
    public const NOT_HONOURED = 'not_honoured_by_carrier';
    public const PARTIAL = 'partial';
    public const STALE_RATE = 'stale_rate';
    public const UNKNOWN_CARRIER = 'unknown_carrier';
    public const NOT_GRANTED = 'not_granted';
    public const NO_METHOD = 'no_method';
    public const ALL = [
        self::EFFECTIVE,
        self::NOT_HONOURED,
        self::PARTIAL,
        self::STALE_RATE,
        self::UNKNOWN_CARRIER,
        self::NOT_GRANTED,
        self::NO_METHOD,
    ];

    /**
     * @param string $verdict self::* verdict code
     * @param string $message
     * @param array[] $rules granting rules, each with id, name, mode
     * @param bool $addressFlag shipping address free_shipping
     * @param array[] $items shippable items flagged free_shipping, each with item_id, sku, qty
     * @param float $freeBoxes
     * @param float $packageQty
     * @param string|null $method selected shipping method code
     * @param float|null $ratePrice stored rate price of the selected method (base currency)
     * @param string $semantics self::SEMANTICS_*
     * @param bool $freeCarrierEnabled carriers/freeshipping/active
     * @param float|null $freeCarrierSubtotal carriers/freeshipping/free_shipping_subtotal
     * @param string|null $rateUpdatedAt
     * @param string|null $quoteUpdatedAt
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string $verdict,
        private readonly string $message,
        private readonly array $rules = [],
        private readonly bool $addressFlag = false,
        private readonly array $items = [],
        private readonly float $freeBoxes = 0.0,
        private readonly float $packageQty = 0.0,
        private readonly ?string $method = null,
        private readonly ?float $ratePrice = null,
        private readonly string $semantics = self::SEMANTICS_UNKNOWN,
        private readonly bool $freeCarrierEnabled = false,
        private readonly ?float $freeCarrierSubtotal = null,
        private readonly ?string $rateUpdatedAt = null,
        private readonly ?string $quoteUpdatedAt = null
    ) {
        if (!in_array($verdict, self::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown free-shipping verdict "%s".', $verdict));
        }
    }

    /**
     * Verdict code.
     *
     * @return string
     */
    public function getVerdict(): string
    {
        return $this->verdict;
    }

    /**
     * Whether the customer pays nothing for shipping, or no method is selected yet.
     *
     * @return bool
     */
    public function isEffective(): bool
    {
        return $this->verdict === self::EFFECTIVE || ($this->verdict === self::NO_METHOD && $this->method === null);
    }

    /**
     * Human-readable verdict.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Granting rules.
     *
     * @return array[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Shipping address free_shipping flag.
     *
     * @return bool
     */
    public function hasAddressFlag(): bool
    {
        return $this->addressFlag;
    }

    /**
     * Items flagged free_shipping.
     *
     * @return array[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Boxes shipped free by item flags.
     *
     * @return float
     */
    public function getFreeBoxes(): float
    {
        return $this->freeBoxes;
    }

    /**
     * Shippable boxes of the address.
     *
     * @return float
     */
    public function getPackageQty(): float
    {
        return $this->packageQty;
    }

    /**
     * Selected shipping method.
     *
     * @return string|null
     */
    public function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * Stored rate price of the selected method.
     *
     * @return float|null
     */
    public function getRatePrice(): ?float
    {
        return $this->ratePrice;
    }

    /**
     * Which free-shipping flags the carrier honours.
     *
     * @return string
     */
    public function getSemantics(): string
    {
        return $this->semantics;
    }

    /**
     * Whether the Free Shipping carrier is enabled.
     *
     * @return bool
     */
    public function isFreeCarrierEnabled(): bool
    {
        return $this->freeCarrierEnabled;
    }

    /**
     * Free Shipping carrier minimum order amount.
     *
     * @return float|null
     */
    public function getFreeCarrierSubtotal(): ?float
    {
        return $this->freeCarrierSubtotal;
    }

    /**
     * When the stored rate was saved.
     *
     * @return string|null
     */
    public function getRateUpdatedAt(): ?string
    {
        return $this->rateUpdatedAt;
    }

    /**
     * When the quote was last saved.
     *
     * @return string|null
     */
    public function getQuoteUpdatedAt(): ?string
    {
        return $this->quoteUpdatedAt;
    }

    /**
     * Whether the stored rate predates the last quote change.
     *
     * @return bool
     */
    public function isRateOlderThanQuote(): bool
    {
        if ($this->rateUpdatedAt === null || $this->quoteUpdatedAt === null) {
            return false;
        }

        return strtotime($this->rateUpdatedAt) < strtotime($this->quoteUpdatedAt);
    }

    /**
     * JSON-ready representation.
     *
     * @param int $precision
     * @return array<string, mixed>
     */
    public function toArray(int $precision = 2): array
    {
        return [
            'verdict' => $this->verdict,
            'verdict_label' => Labels::freeShippingVerdict($this->verdict),
            'message' => $this->message,
            'rules' => array_map(
                static fn (array $rule): array => $rule
                    + ['mode_label' => Labels::freeShippingMode((string)$rule['mode'])],
                $this->rules
            ),
            'address_flag' => $this->addressFlag,
            'items' => array_map(
                fn (array $item): array => array_merge($item, ['qty' => $this->formatQty((float)$item['qty'])]),
                $this->items
            ),
            'free_boxes' => $this->formatQty($this->freeBoxes),
            'package_qty' => $this->formatQty($this->packageQty),
            'method' => $this->method,
            'rate_price' => $this->formatAmount($this->ratePrice, $precision),
            'semantics' => $this->semantics,
            'semantics_label' => Labels::semantics($this->semantics),
            'free_shipping_carrier' => [
                'enabled' => $this->freeCarrierEnabled,
                'enabled_label' => Labels::enabled($this->freeCarrierEnabled),
                'subtotal' => $this->formatAmount($this->freeCarrierSubtotal, $precision),
            ],
            'rate_updated_at' => $this->rateUpdatedAt,
            'quote_updated_at' => $this->quoteUpdatedAt,
            'rate_older_than_quote' => $this->isRateOlderThanQuote(),
        ];
    }
}
