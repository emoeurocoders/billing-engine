<?php

namespace Omni\BillingEngine\Support;

/**
 * Outcome of a guard. PASS continues the pipeline; SKIP defers the row to the
 * next cycle (transient); DEAD marks it permanently done (hard stop).
 */
final class GuardResult
{
    public const PASS = 'pass';
    public const SKIP = 'skip';
    public const DEAD = 'dead';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
    ) {}

    public static function pass(): self          { return new self(self::PASS); }
    public static function skip(string $r): self  { return new self(self::SKIP, $r); }
    public static function dead(string $r): self  { return new self(self::DEAD, $r); }

    public function passed(): bool { return $this->outcome === self::PASS; }
    public function isDead(): bool { return $this->outcome === self::DEAD; }
    public function isSkip(): bool { return $this->outcome === self::SKIP; }
}
