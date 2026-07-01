<?php

namespace Omni\BillingEngine\Contracts;

use Omni\BillingEngine\Support\GatewayResult;

/**
 * Payment gateway abstraction. NMI and Inovio both expose doCharge/doRebill/
 * doCapture and a `response` map; adapters translate the canonical payload to
 * gateway-specific keys and normalise the response to a GatewayResult.
 *
 * Canonical payload keys (adapters map as needed):
 *   amount, mid_id, member_id, source_tr_id, ip, country, device,
 *   udf_1, udf_2, descriptor, card_number, cvv, card_exp
 */
interface GatewayClient
{
    /** Rebill an existing customer/token (doRebill). */
    public function rebill(array $payload): GatewayResult;

    /** Charge with explicit card data, e.g. an AZ token (doCharge). */
    public function charge(array $payload): GatewayResult;

    /** Capture/settle a previously authorised transaction (doCapture). */
    public function capture(string $transactionRef): GatewayResult;

    /** Canonical decline code for a raw gateway response_code. */
    public function normaliseDeclineCode(?string $rawCode): ?string;
}
