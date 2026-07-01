<?php

namespace Omni\BillingEngine\Guards;

use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Hard-decline / negative-database check (chargebacks, fraud, do-not-bill).
 * Returns DEAD so the row is never retried. The actual lookup is vertical
 * specific, so it's delegated to an app-bound closure:
 *
 *   app()->instance('billing.negativeDb', fn(string $memberId) => ?string $reason);
 */
class NegativeDb implements BillingGuard
{
    public function key(): string { return 'negative_db'; }

    public function check(BillingContext $ctx): GuardResult
    {
        if (!app()->bound('billing.negativeDb')) {
            return GuardResult::pass();
        }

        $reason = app('billing.negativeDb')($ctx->memberId());

        return $reason ? GuardResult::dead("negative_db:{$reason}") : GuardResult::pass();
    }
}
