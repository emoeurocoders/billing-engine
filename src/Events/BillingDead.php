<?php

namespace Omni\BillingEngine\Events;

use Omni\BillingEngine\Support\BillingContext;

class BillingDead
{
    public function __construct(
        public readonly BillingContext $ctx,
        public readonly ?string $reason,
    ) {}
}
