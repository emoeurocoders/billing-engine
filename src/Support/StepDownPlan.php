<?php

namespace Omni\BillingEngine\Support;

/**
 * The next step-down rung to schedule after a decline: a (usually lower) amount
 * after a delay, optionally on a different MID. Produced by StepDownPlanner from
 * the per-type `stepdowns` config.
 */
final class StepDownPlan
{
    public function __construct(
        public readonly ?float $amount,           // null = keep the current amount
        public readonly int $delayDays,
        public readonly string $midStrategy = 'sticky', // sticky | match | new
        public readonly array $rule = [],         // the raw matched rule (for events/debug)
    ) {}
}
