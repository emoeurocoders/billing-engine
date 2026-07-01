<?php

namespace Omni\BillingEngine\Events;

use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GatewayResult;
use Omni\BillingEngine\Support\MidDecision;

class BillingSucceeded
{
    public function __construct(
        public readonly BillingContext $ctx,
        public readonly MidDecision $mid,
        public readonly GatewayResult $result,
    ) {}
}
