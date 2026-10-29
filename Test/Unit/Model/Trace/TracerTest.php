<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Test\Unit\Model\Trace;

use DmLab\PriceExplain\Model\Report\CollectorStep;
use DmLab\PriceExplain\Model\Trace\Tracer;
use PHPUnit\Framework\TestCase;

class TracerTest extends TestCase
{
    public function testInactiveByDefault(): void
    {
        self::assertFalse((new Tracer())->isActive());
    }

    public function testRecordsInOrderAndKeepsDataAfterStop(): void
    {
        $tracer = new Tracer();
        $tracer->start();
        $subtotal = $this->step('subtotal', 'shipping');
        $billing = $this->step('subtotal', 'billing');
        $tracer->addCollectorStep($subtotal);
        $tracer->addCollectorStep($billing);
        $tracer->stop();

        self::assertFalse($tracer->isActive());
        self::assertSame([$subtotal, $billing], $tracer->getCollectorSteps());
    }

    public function testStartResetsPreviousRun(): void
    {
        $tracer = new Tracer();
        $tracer->start();
        $tracer->addCollectorStep($this->step('subtotal', 'shipping'));
        $tracer->start();

        self::assertTrue($tracer->isActive());
        self::assertSame([], $tracer->getCollectorSteps());
    }

    public function testCapturesValidatedValuesUntilCaptureEnds(): void
    {
        $tracer = new Tracer();
        $node = new \stdClass();
        $other = new \stdClass();

        self::assertFalse($tracer->isCapturing());
        $tracer->beginCapture();
        self::assertTrue($tracer->isCapturing());
        $tracer->recordValidatedValue($node, 1);
        $tracer->recordValidatedValue($node, null);
        $tracer->recordValidatedValue($other, [3]);

        self::assertTrue($tracer->hasValidatedValue($node));
        self::assertNull($tracer->getValidatedValue($node));
        self::assertSame([3], $tracer->getValidatedValue($other));
        $tracer->forgetValidatedValue($other);
        self::assertFalse($tracer->hasValidatedValue($other));
        self::assertFalse($tracer->isActive());

        $tracer->endCapture();
        self::assertFalse($tracer->isCapturing());
        self::assertFalse($tracer->hasValidatedValue($node));
    }

    private function step(string $code, string $address): CollectorStep
    {
        return new CollectorStep($code, 'Magento\\X', false, $address, []);
    }
}
