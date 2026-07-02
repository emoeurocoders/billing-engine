<?php

namespace Omni\BillingEngine\Guards;

use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Contracts\MidResolver;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;
use Omni\BillingEngine\Support\Reasons;

/**
 * Defers the row if the member's MID can't take the charge today (inactive,
 * killed, daily cap reached). The resolver is the authority on MID usability:
 * if it can't resolve a usable MID, we SKIP to the next cycle rather than burn
 * an attempt. Resolution is cached onto the context for the handler to reuse.
 */
class MidCap implements BillingGuard
{
    public function __construct(private MidResolver $mids) {}

    public function key(): string { return 'mid_cap'; }

    public function check(BillingContext $ctx): GuardResult
    {
        // Only meaningful for sticky selection; rotation picks fresh each time.
        if ($ctx->selection() !== 'sticky') {
            return GuardResult::pass();
        }

        $mid = $this->mids->resolveStickyMid((string) $ctx->midId(), $ctx);

        if (!$mid) {
            return GuardResult::skip(Reasons::NO_MID);
        }

        $ctx->mid = $mid; // hand the resolved MID to the handler (avoids a 2nd lookup)
        return GuardResult::pass();
    }
}
