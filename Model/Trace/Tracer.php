<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\PriceExplain\Model\Trace;

use DmLab\PriceExplain\Model\Report\CollectorStep;

/**
 * Request-wide switch for the tracing plugins plus the data they record; inactive by default.
 */
class Tracer
{
    /**
     * @var bool
     */
    private bool $active = false;

    /**
     * @var CollectorStep[]
     */
    private array $steps = [];

    /**
     * @var bool
     */
    private bool $capturing = false;

    /**
     * @var array<int, mixed> compared values keyed by condition node object ID
     */
    private array $validatedValues = [];

    /**
     * Activate tracing and drop data from a previous run.
     *
     * @return void
     */
    public function start(): void
    {
        $this->steps = [];
        $this->active = true;
    }

    /**
     * Deactivate tracing; recorded data stays readable.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->active = false;
    }

    /**
     * Whether plugins should record.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Record one collector run.
     *
     * @param CollectorStep $step
     * @return void
     */
    public function addCollectorStep(CollectorStep $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * Collector steps in execution order.
     *
     * @return CollectorStep[]
     */
    public function getCollectorSteps(): array
    {
        return $this->steps;
    }

    /**
     * Start recording compared condition values.
     *
     * @return void
     */
    public function beginCapture(): void
    {
        $this->capturing = true;
        $this->validatedValues = [];
    }

    /**
     * Stop recording compared condition values and drop them.
     *
     * @return void
     */
    public function endCapture(): void
    {
        $this->capturing = false;
        $this->validatedValues = [];
    }

    /**
     * Whether the condition capture plugin should record.
     *
     * @return bool
     */
    public function isCapturing(): bool
    {
        return $this->capturing;
    }

    /**
     * Record the value a condition node compared; the last one wins.
     *
     * @param object $node
     * @param mixed $value
     * @return void
     */
    public function recordValidatedValue(object $node, mixed $value): void
    {
        $this->validatedValues[spl_object_id($node)] = $value;
    }

    /**
     * Whether a compared value was recorded for the node.
     *
     * @param object $node
     * @return bool
     */
    public function hasValidatedValue(object $node): bool
    {
        return array_key_exists(spl_object_id($node), $this->validatedValues);
    }

    /**
     * Last compared value recorded for the node.
     *
     * @param object $node
     * @return mixed
     */
    public function getValidatedValue(object $node): mixed
    {
        return $this->validatedValues[spl_object_id($node)] ?? null;
    }

    /**
     * Drop the recorded value of the node.
     *
     * @param object $node
     * @return void
     */
    public function forgetValidatedValue(object $node): void
    {
        unset($this->validatedValues[spl_object_id($node)]);
    }
}
