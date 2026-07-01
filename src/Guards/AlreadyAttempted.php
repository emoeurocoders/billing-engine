<?php

namespace Omni\BillingEngine\Guards;

use Omni\BillingEngine\Contracts\AttemptLogger;
use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Belt-and-suspenders on top of the UNIQUE(idempotency_key): if the member was
 * already attempted this cycle, don't bill again. Approved → done; declined →
 * defer to next cycle.
 */
class AlreadyAttempted implements BillingGuard
{
    public function __construct(private AttemptLogger $log) {}

    public function key(): string { return 'already_attempted'; }

    public function check(BillingContext $ctx): GuardResult
    {
        // Step-down retries are intentional re-attempts within the same cycle —
        // they must bypass the "already attempted this cycle" check.
        if ((int) ($ctx->row->step ?? 0) > 0) {
            return GuardResult::pass();
        }

        if (!$this->log->alreadyAttempted($ctx)) {
            return GuardResult::pass();
        }

        return $this->log->lastApprovedThisCycle($ctx)
            ? GuardResult::dead('already_attempted_approved')
            : GuardResult::skip('already_attempted_declined');
    }
}
