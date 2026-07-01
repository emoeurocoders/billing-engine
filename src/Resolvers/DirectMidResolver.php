<?php

namespace Omni\BillingEngine\Resolvers;

use Illuminate\Support\Facades\DB;
use Omni\BillingEngine\Contracts\MidResolver;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\MidDecision;

/**
 * Default MID resolver — reads the configured config table (mids / pdf_mids /
 * manuals_mids, all same-shape, on the bigentertainment connection). Honors
 * redirect_mid for sticky failover. Works with NO mid-balancer present.
 *
 * A vertical that wants the load-balancer's intelligence binds its own
 * MidResolver instead (see examples/SportsMidBalancerAdapter.php).
 */
class DirectMidResolver implements MidResolver
{
    private function table()
    {
        return DB::connection(config('billing-engine.mids.connection'))
            ->table(config('billing-engine.mids.table', 'mids'));
    }

    public function resolveStickyMid(string $midId, BillingContext $ctx): ?MidDecision
    {
        $mid = $this->table()->where('mid_id', $midId)->first();

        if (!$mid) {
            return null;
        }

        // Follow a configured redirect to a live MID.
        if (!empty($mid->redirect_mid)) {
            $redirect = $this->table()->where('mid_id', $mid->redirect_mid)->first();
            if ($redirect) {
                $mid = $redirect;
            }
        }

        if ((int) ($mid->active ?? 0) !== 1 || in_array($mid->status ?? '', ['killed', 'high_declines'], true)) {
            return null;
        }

        return $this->toDecision($mid);
    }

    public function resolveRotationMid(BillingContext $ctx): ?MidDecision
    {
        // Minimal rotation: active, rebills-eligible, lowest daily_order first.
        $mid = $this->table()
            ->where('active', 1)
            ->whereNotIn('status', ['killed', 'high_declines'])
            ->orderByRaw('COALESCE(daily_order, 999999) ASC')
            ->inRandomOrder()
            ->first();

        return $mid ? $this->toDecision($mid) : null;
    }

    public function resolveMatchingMid(string $originalMidId, BillingContext $ctx): ?MidDecision
    {
        $original = $this->table()->where('mid_id', $originalMidId)->first();
        if (!$original || empty($original->descriptor)) {
            return null;
        }

        $match = $this->table()
            ->where('descriptor', $original->descriptor)
            ->where('mid_id', '!=', $originalMidId)
            ->where('active', 1)
            ->whereNotIn('status', ['killed', 'high_declines'])
            ->inRandomOrder()
            ->first();

        return $match ? $this->toDecision($match) : null;
    }

    public function recordResult(MidDecision $mid, bool $approved, array $details = []): void
    {
        // Default impl keeps no separate counter table (the schedule row +
        // attempt log already capture outcomes). Override in an adapter to feed
        // mid-balancer daily stats.
    }

    private function toDecision($mid): MidDecision
    {
        return new MidDecision(
            midId: (string) $mid->mid_id,
            descriptor: $mid->descriptor ?? null,
            bank: $mid->bank ?? null,
            state: $mid,
        );
    }
}
