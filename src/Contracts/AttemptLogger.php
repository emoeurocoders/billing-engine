<?php

namespace Omni\BillingEngine\Contracts;

use Omni\BillingEngine\Support\BillingContext;

/**
 * Persists billing attempts and answers "already attempted this cycle?".
 * Default impl writes the legacy per-vertical log table (rebill_sports, ...);
 * a vertical may swap it without touching handlers.
 */
interface AttemptLogger
{
    /** Has this member already been attempted in the current cycle window? */
    public function alreadyAttempted(BillingContext $ctx): bool;

    /** Was the most recent attempt this cycle an approval? */
    public function lastApprovedThisCycle(BillingContext $ctx): bool;

    public function record(BillingContext $ctx, bool $approved, ?string $declineCode, ?string $transactionId): void;
}
