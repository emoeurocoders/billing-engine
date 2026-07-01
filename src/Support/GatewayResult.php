<?php

namespace Omni\BillingEngine\Support;

/**
 * Normalised gateway response. Handlers only ever see this shape, never the
 * raw NMI/Inovio response arrays.
 */
final class GatewayResult
{
    public function __construct(
        public readonly bool $approved,
        public readonly ?string $responseCode = null,
        public readonly ?string $responseText = null,
        public readonly ?string $transactionId = null,
        public readonly array $raw = [],
    ) {}

    public static function approved(?string $transactionId = null, array $raw = []): self
    {
        return new self(true, '100', 'APPROVED', $transactionId, $raw);
    }

    public static function declined(?string $code, ?string $text = null, ?string $transactionId = null, array $raw = []): self
    {
        return new self(false, $code, $text, $transactionId, $raw);
    }
}
