<?php

namespace Omni\BillingEngine\Support;

/**
 * Stored ("AZ") card data resolved for a member, used to charge via the gateway's
 * card path (doCharge) instead of the token rebill path (doRebill). Produced by a
 * CardVault the app binds — the package never sees the vault/decrypt internals.
 *
 * Faithful to the legacy getAzKey() result plus the billing/exp fields the legacy
 * rebillCC AZ block read off the ledger transaction:
 *   - cardNumber / cvv  → from getAzKey() ("key" / "az")
 *   - cardExp (MMYY)     → padded expiremonth . expireyear
 *   - billing            → setBilling() fields (uid, email, name, zip, country, ip, device)
 */
final class CardData
{
    /**
     * @param array<string,mixed> $billing setBilling() fields
     * @param array<string,mixed> $meta    optional overrides (udf_3, order_id, order_desc, brand)
     */
    public function __construct(
        public readonly string $cardNumber,
        public readonly string $cvv,
        public readonly ?string $cardExp = null,
        public readonly array $billing = [],
        public readonly array $meta = [],
    ) {}
}
