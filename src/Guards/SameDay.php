<?php

namespace Omni\BillingEngine\Guards;

use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Skip if the member already has an approved transaction today (prevents a
 * same-day double charge). The lookup is delegated to a closure bound by the
 * app, so the engine stays decoupled from each vertical's transaction table.
 *
 * Bind in the app: app()->instance('billing.sameDayCheck', fn(string $memberId) => bool);
 */
class SameDay implements BillingGuard
{
    public function key(): string { return 'same_day'; }

    public function check(BillingContext $ctx): GuardResult
    {
        // A scheduled step-down retry is intentional — don't let same-day block it.
        if ((int) ($ctx->row->step ?? 0) > 0) {
            return GuardResult::pass();
        }

        $check = app()->bound('billing.sameDayCheck') ? app('billing.sameDayCheck') : null;

        if ($check && $check($ctx->memberId())) {
            return GuardResult::skip('same_day_transaction');
        }

        return GuardResult::pass();
    }
}
