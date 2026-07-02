<?php

namespace Omni\BillingEngine\Support;

use Omni\BillingEngine\Models\BillingSchedule;

/**
 * Everything a handler/guard needs for one billing attempt, assembled once per
 * job. Carries the schedule row, the resolved per-type config, and mutable
 * slots filled in as the pipeline runs (mid, result).
 */
final class BillingContext
{
    public ?MidDecision $mid = null;
    public ?GatewayResult $result = null;

    public function __construct(
        public readonly BillingSchedule $row,
        public readonly string $vertical,
        public readonly string $billingType,
        public readonly array $typeConfig,
    ) {}

    public function memberId(): string  { return (string) $this->row->member_id; }
    public function midId(): ?string    { return $this->row->mid_id; }
    public function amount(): float     { return (float) $this->row->amount; }
    public function cardType(): string  { return (string) $this->row->card_type; }
    public function cycle(): string     { return (string) $this->row->cycle; }

    public function logTable(): ?string { return $this->typeConfig['log_table'] ?? null; }
    public function selection(): string { return $this->typeConfig['selection'] ?? 'sticky'; }

    /**
     * The descriptor UDF2 for this attempt. Config may be a single string (used
     * for every card) OR a per-card map like ['cc' => 'CCR', 'pp' => 'PPR'] —
     * legacy rebillCC sent CCR, rebillPP sent PPR, so a unified rebill type MUST
     * pick by card. Unknown card falls back to the first map entry, then null.
     */
    public function udf2(): ?string
    {
        $u = $this->typeConfig['udf2'] ?? null;

        if (is_array($u)) {
            return $u[$this->cardType()] ?? (reset($u) ?: null);
        }

        return $u;
    }
}
