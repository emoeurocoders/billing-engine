<?php

namespace Omni\BillingEngine\Guards;

use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Stop billing a member who has hit the per-cycle decline ceiling. Threshold
 * is config (per type or global). Decline count is delegated to the app so the
 * engine doesn't hardcode a transaction-table query.
 *
 *   app()->instance('billing.declineCount', fn(string $memberId, string $type) => int);
 */
class MaxDeclines implements BillingGuard
{
    public function key(): string { return 'max_declines'; }

    public function check(BillingContext $ctx): GuardResult
    {
        $max = (int) ($ctx->typeConfig['max_declines'] ?? config('billing-engine.max_declines', 3));

        if ($max <= 0 || !app()->bound('billing.declineCount')) {
            return GuardResult::pass();
        }

        $count = (int) app('billing.declineCount')($ctx->memberId(), $ctx->billingType);

        return $count >= $max
            ? GuardResult::dead('max_declines')
            : GuardResult::pass();
    }
}
