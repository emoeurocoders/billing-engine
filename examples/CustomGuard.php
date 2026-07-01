<?php

/**
 * EXAMPLE — a vertical-specific pre-charge rule. Implement BillingGuard, give it
 * a key, register it, and add that key to the type's `guards` array in config.
 *
 *   // in a service provider:
 *   $this->app->make(GuardRunner::class)->register('chase_bin', ChaseBinGuard::class);
 *   // in config/billing-engine.php types.rebill.guards: [..., 'chase_bin']
 */

namespace App\Billing;

use App\Models\ChaseBins;
use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

class ChaseBinGuard implements BillingGuard
{
    public function key(): string { return 'chase_bin'; }

    public function check(BillingContext $ctx): GuardResult
    {
        $bin = $ctx->row->meta['bin'] ?? null;

        // Example policy: defer Chase BINs to the next cycle on this vertical.
        if ($bin && ChaseBins::where('bin', $bin)->exists()) {
            return GuardResult::skip('chase_bin_deferred');
        }

        return GuardResult::pass();
    }
}
