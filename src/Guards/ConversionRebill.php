<?php

namespace Omni\BillingEngine\Guards;

use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Skip if the member already converted/rebilled inside the current window
 * (the legacy conversionRebill() check) — avoids billing someone who already
 * paid via another path this cycle. Delegated to an app-bound closure.
 *
 *   app()->instance('billing.conversionRebill', fn(string $memberId) => bool);
 */
class ConversionRebill implements BillingGuard
{
    public function key(): string { return 'conversion_rebill'; }

    public function check(BillingContext $ctx): GuardResult
    {
        if (!app()->bound('billing.conversionRebill')) {
            return GuardResult::pass();
        }

        return app('billing.conversionRebill')($ctx->memberId())
            ? GuardResult::skip('conversion_rebill')
            : GuardResult::pass();
    }
}
