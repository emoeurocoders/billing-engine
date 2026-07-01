<?php

/**
 * EXAMPLE — a MidResolver that delegates to the mid-balancer package, so sports
 * keeps its load-balancing/sticky-validation intelligence while the engine
 * itself has ZERO dependency on mid-balancer. Other verticals can skip this and
 * use the package's default DirectMidResolver.
 *
 * Place at App\Billing\SportsMidBalancerAdapter and bind it (see
 * AppServiceProviderWiring.php).
 */

namespace App\Billing;

use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Contracts\MidResolver;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\MidDecision;
use Omni\MidBalancer\Facades\MidBalancer;
use Omni\MidBalancer\Models\RebillDailyStats;

class SportsMidBalancerAdapter implements MidResolver
{
    public function resolveStickyMid(string $midId, BillingContext $ctx): ?MidDecision
    {
        // Honour redirect + sticky validation against the mids registry.
        $mid = DB::table(config('billing-engine.mids.table', 'mids'))
            ->where('mid_id', $this->redirect($midId))
            ->where('active', 1)
            ->whereNotIn('status', ['high_declines', 'killed'])
            ->first();

        if (!$mid) {
            return null;
        }

        // Ensure today's RebillDailyStats row exists, then hand it back as state.
        $stats = MidBalancer::getOrCreateRebillDailyStats(
            $mid->mid_id, $ctx->vertical, $mid->processor ?? '', $mid->mid_name ?? null,
            $mid->active_date ?? null, $mid->descriptor ?? null,
        );

        return new MidDecision($mid->mid_id, $mid->descriptor, $mid->bank, $stats);
    }

    public function resolveRotationMid(BillingContext $ctx): ?MidDecision
    {
        // Conversions / "new MID" step-downs: let the balancer pick.
        $stats = MidBalancer::selectMid($ctx->vertical, ['amount' => $ctx->amount()]);
        return $stats ? new MidDecision($stats->mid_id, $stats->descriptor ?? null, null, $stats) : null;
    }

    public function resolveMatchingMid(string $originalMidId, BillingContext $ctx): ?MidDecision
    {
        // "Matching" step-down: a different MID with the same descriptor.
        $descriptor = DB::table(config('billing-engine.mids.table', 'mids'))
            ->where('mid_id', $originalMidId)->value('descriptor');

        if (!$descriptor) {
            return null;
        }

        $match = MidBalancer::selectMatchingMids($descriptor, $ctx->vertical, $originalMidId, 1)->first();

        return $match ? new MidDecision($match->mid_id, $descriptor, null, $match) : null;
    }

    public function recordResult(MidDecision $mid, bool $approved, array $details = []): void
    {
        if ($mid->state instanceof RebillDailyStats) {
            MidBalancer::recordRebillResult(
                mid: $mid->state,
                approved: $approved,
                declineCode: $details['declineCode'] ?? null,
                bankResponseCode: $details['bankResponseCode'] ?? null,
                declineReason: $details['declineReason'] ?? null,
                transactionId: $details['transactionId'] ?? null,
            );
        }
    }

    private function redirect(string $midId): string
    {
        $r = DB::table(config('billing-engine.mids.table', 'mids'))
            ->where('mid_id', $midId)->value('redirect_mid');
        return $r ?: $midId;
    }
}
