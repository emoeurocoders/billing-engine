<?php

namespace Omni\BillingEngine\Contracts;

use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\MidDecision;

/**
 * Resolves which MID to bill on, and records the runtime result.
 *
 * The package ships DirectMidResolver (reads the configured mids table:
 * mids/pdf_mids/manuals_mids on bigentertainment). A vertical may bind its
 * own implementation — e.g. sports binds an adapter delegating to the
 * mid-balancer package — without the engine depending on it.
 */
interface MidResolver
{
    /**
     * Validate/redirect the member's sticky MID (rebills, crosses, settles).
     * Returns null when no usable MID exists (caller skips the row).
     */
    public function resolveStickyMid(string $midId, BillingContext $ctx): ?MidDecision;

    /**
     * Pick a MID by rotation/load-balancing (conversions, initials).
     */
    public function resolveRotationMid(BillingContext $ctx): ?MidDecision;

    /**
     * Pick a DIFFERENT MID sharing the original MID's descriptor — used by the
     * "matching" step-down rung. Returns null if no match is available.
     */
    public function resolveMatchingMid(string $originalMidId, BillingContext $ctx): ?MidDecision;

    /**
     * Record the outcome against MID runtime state (counters / daily stats).
     */
    public function recordResult(MidDecision $mid, bool $approved, array $details = []): void;
}
