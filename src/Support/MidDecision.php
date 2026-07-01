<?php

namespace Omni\BillingEngine\Support;

/**
 * The MID chosen for a charge, plus whatever runtime-state handle the resolver
 * needs to record the result (e.g. a RebillDailyStats model for the
 * mid-balancer adapter). `state` is opaque to the engine.
 */
final class MidDecision
{
    public function __construct(
        public readonly string $midId,
        public readonly ?string $descriptor = null,
        public readonly ?string $bank = null,
        public readonly mixed $state = null,
        public readonly array $meta = [],
    ) {}
}
