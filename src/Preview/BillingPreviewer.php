<?php

namespace Omni\BillingEngine\Preview;

use Omni\BillingEngine\Contracts\MidResolver;
use Omni\BillingEngine\Pipeline\GuardRunner;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\PreviewResult;

/**
 * Read-only "what would happen" engine behind `billing:dispatch --dry-run`.
 *
 * It walks the SAME pre-charge decisioning as BillingHandler::handle() — the
 * config-ordered guard pipeline, then MID resolution — and stops exactly where
 * the real charge would occur. It NEVER calls the gateway, NEVER records an
 * attempt, and NEVER mutates the schedule row, so it is safe to run against
 * live production data before cutover to see precisely who would be billed.
 *
 * The guard set (already_attempted, same_day, negative_db, max_declines,
 * mid_cap, conversion_rebill) and the DirectMidResolver / mid-balancer adapter
 * are all read-only checks, which is what makes this preview faithful: the
 * decision here is the decision the worker would reach a moment later.
 */
class BillingPreviewer
{
    public function __construct(
        private GuardRunner $guards,
        private MidResolver $mids,
    ) {}

    public function preview(BillingContext $ctx): PreviewResult
    {
        // 1. Guards — identical pipeline to the live handler. A non-pass verdict
        //    is exactly what would skip/kill the row instead of charging it.
        $verdict = $this->guards->run($ctx, $ctx->typeConfig['guards'] ?? []);
        if (!$verdict->passed()) {
            return $verdict->isDead()
                ? PreviewResult::dead($verdict->reason)
                : PreviewResult::skip($verdict->reason);
        }

        // 2. MID resolution — mirrors BillingHandler::resolveMid() (kept in sync
        //    deliberately; both read the configured mids table / balancer).
        $mid = $this->resolveMid($ctx);
        if (!$mid) {
            return PreviewResult::noMid();
        }

        // This is where the live flow would charge. We stop here.
        return PreviewResult::charge($mid);
    }

    /**
     * Faithful copy of BillingHandler::resolveMid() selection logic. Step-down
     * rows carry a mid_strategy in meta; fresh rows use the type's selection.
     */
    private function resolveMid(BillingContext $ctx)
    {
        $strategy = $ctx->row->meta['mid_strategy'] ?? null;

        if ($strategy === 'match') {
            return $this->mids->resolveMatchingMid((string) $ctx->midId(), $ctx);
        }

        if ($strategy === 'new') {
            return $this->mids->resolveRotationMid($ctx);
        }

        return $ctx->selection() === 'rotation'
            ? $this->mids->resolveRotationMid($ctx)
            : $this->mids->resolveStickyMid((string) $ctx->midId(), $ctx);
    }
}
