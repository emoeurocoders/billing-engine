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
}
