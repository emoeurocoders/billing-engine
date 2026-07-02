<?php

namespace Omni\BillingEngine\Support;

/**
 * The decision a dry-run reached for ONE due schedule row, WITHOUT charging or
 * writing anything. Mirrors what BillingHandler::handle() would do up to (but
 * not including) the gateway charge:
 *
 *   - CHARGE  → guards passed and a MID resolved; this member WOULD be billed
 *               `amount` on `mid`.
 *   - SKIP    → a guard returned SKIP (e.g. already attempted, same-day); the
 *               row would be deferred to next cycle. No charge.
 *   - DEAD    → a guard returned DEAD (e.g. already approved this cycle, max
 *               declines); the row would be parked permanently. No charge.
 *   - NO_MID  → guards passed but no usable MID resolved; the row would be
 *               deferred. No charge.
 */
final class PreviewResult
{
    public const CHARGE = 'charge';
    public const SKIP   = 'skip';
    public const DEAD   = 'dead';
    public const NO_MID = 'no_mid';

    public function __construct(
        public readonly string $disposition,
        public readonly ?string $reason = null,
        public readonly ?MidDecision $mid = null,
    ) {}

    public static function charge(MidDecision $mid): self
    {
        return new self(self::CHARGE, null, $mid);
    }

    public static function skip(?string $reason): self
    {
        return new self(self::SKIP, $reason);
    }

    public static function dead(?string $reason): self
    {
        return new self(self::DEAD, $reason);
    }

    public static function noMid(): self
    {
        return new self(self::NO_MID, Reasons::NO_MID);
    }

    public function willCharge(): bool
    {
        return $this->disposition === self::CHARGE;
    }
}
