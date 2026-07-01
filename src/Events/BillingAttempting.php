<?php

namespace Omni\BillingEngine\Events;

use Omni\BillingEngine\Support\BillingContext;

class BillingAttempting
{
    public function __construct(public readonly BillingContext $ctx) {}
}
