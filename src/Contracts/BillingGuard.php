<?php

namespace Omni\BillingEngine\Contracts;

use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * A single pre-charge rule. Guards run as an ordered, config-driven pipeline.
 * A vertical can add/remove/reorder guards via config, or register its own.
 */
interface BillingGuard
{
    /** Short key used in the per-type `guards` config array. */
    public function key(): string;

    public function check(BillingContext $ctx): GuardResult;
}
