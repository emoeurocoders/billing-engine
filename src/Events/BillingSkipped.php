<?php

namespace Omni\BillingEngine\Events;

use Omni\BillingEngine\Support\BillingContext;

class BillingSkipped
{
    public function __construct(
        public readonly BillingContext $ctx,
        public readonly string $reason,
    ) {}
}
