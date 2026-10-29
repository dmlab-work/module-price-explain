<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Plugin\Rule;

use DmLab\PriceExplain\Model\Trace\Tracer;
use DmLab\PriceExplain\Plugin\Rule\CaptureValidatedValuePlugin;
use Magento\Rule\Model\Condition\AbstractCondition;
use PHPUnit\Framework\TestCase;

class CaptureValidatedValuePluginTest extends TestCase
{
    public function testIgnoresValuesWhenNotCapturing(): void
    {
        $tracer = new Tracer();
        $node = $this->createStub(AbstractCondition::class);

        self::assertNull((new CaptureValidatedValuePlugin($tracer))->beforeValidateAttribute($node, 5));
        self::assertFalse($tracer->hasValidatedValue($node));
    }

    public function testRecordsLastValuePerNodeWhileCapturing(): void
    {
        $tracer = new Tracer();
        $tracer->beginCapture();
        $plugin = new CaptureValidatedValuePlugin($tracer);
        $node = $this->createStub(AbstractCondition::class);
        $other = $this->createStub(AbstractCondition::class);

        $plugin->beforeValidateAttribute($node, 2.0);
        $plugin->beforeValidateAttribute($node, 5.0);
        self::assertNull($plugin->beforeValidateAttribute($other, ['3', '7']));

        self::assertSame(5.0, $tracer->getValidatedValue($node));
        self::assertSame(['3', '7'], $tracer->getValidatedValue($other));
    }
}
