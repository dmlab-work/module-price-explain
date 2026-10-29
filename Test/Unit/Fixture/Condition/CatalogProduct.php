<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Fixture\Condition;

use Magento\Catalog\Model\Product;

/**
 * Catalog product without constructor dependencies; the resource is injected.
 */
class CatalogProduct extends Product
{
    /**
     * @var mixed
     */
    public mixed $resource = null;

    /**
     * @inheritdoc
     */
    public function getResource()
    {
        return $this->resource;
    }
}
