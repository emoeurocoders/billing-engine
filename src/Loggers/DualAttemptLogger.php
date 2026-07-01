<?php

namespace Omni\BillingEngine\Loggers;

use Omni\BillingEngine\Contracts\AttemptLogger;
use Omni\BillingEngine\Support\BillingContext;

/**
 * Migration logger: WRITES to several loggers (the new unified
 * billing_attempts_{vertical} AND the legacy per-type tables), while READS come
 * from a single configured source.
 *
 * Strategy:
 *   - record()   → fan out to every write target (dual-write).
 *   - reads      → the `read_from` target only. Start on 'legacy' (it has the
 *                  history); once billing_attempts has a full cycle of data,
 *                  flip billing-engine.log.read_from to 'unified' and then drop
 *                  the legacy tables + legacy write target.
 */
class DualAttemptLogger implements AttemptLogger
{
    /**
     * @param AttemptLogger[] $writers all targets to write to
     * @param AttemptLogger   $reader  the single source of truth for reads
     */
    public function __construct(
        private array $writers,
        private AttemptLogger $reader,
    ) {}

    public function alreadyAttempted(BillingContext $ctx): bool
    {
        return $this->reader->alreadyAttempted($ctx);
    }

    public function lastApprovedThisCycle(BillingContext $ctx): bool
    {
        return $this->reader->lastApprovedThisCycle($ctx);
    }

    public function record(BillingContext $ctx, bool $approved, ?string $declineCode, ?string $transactionId): void
    {
        foreach ($this->writers as $writer) {
            $writer->record($ctx, $approved, $declineCode, $transactionId);
        }
    }
}
