<?php

namespace Omni\BillingEngine\Events;

use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\StepDownPlan;

/** Fired when a declined attempt is scheduled to retry on a step-down rung. */
class BillingSteppedDown
{
    public function __construct(
        public readonly BillingContext $ctx,
        public readonly StepDownPlan $plan,
    ) {}
}
