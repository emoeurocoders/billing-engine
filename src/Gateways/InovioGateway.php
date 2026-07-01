<?php

namespace Omni\BillingEngine\Gateways;

use Omni\BillingEngine\Contracts\GatewayClient;
use Omni\BillingEngine\Support\GatewayResult;

/**
 * Adapter over the app's existing Inovio library (App\Library\Inovio). Same
 * contract as NmiGateway — only the payload keys, decline-code map and the
 * "approved" signal differ (Inovio: trans_status_name == 'APPROVED', code 600
 * for CVV decline, service_response otherwise).
 */
class InovioGateway implements GatewayClient
{
    /** Inovio service_response → canonical decline code. Extend via config. */
    private const CODE_MAP = [
        '100' => '0', '600' => '106', // 600 = CVV decline
    ];

    public function __construct(private object $client) {}

    public function rebill(array $payload): GatewayResult
    {
        return $this->result($this->client->doRebill($this->mapPayload($payload)));
    }

    public function charge(array $payload): GatewayResult
    {
        // Stored-card ("AZ") path. Inovio's card charge differs from NMI (no
        // separate setOrder/setBilling); the card fields are folded into the
        // doCharge payload. Refine when an Inovio vertical migrates the AZ flow.
        return $this->result($this->client->doCharge($this->mapChargePayload($payload)));
    }

    public function capture(string $transactionRef): GatewayResult
    {
        return $this->result($this->client->doCapture($transactionRef));
    }

    public function normaliseDeclineCode(?string $rawCode): ?string
    {
        return self::CODE_MAP[$rawCode] ?? $rawCode;
    }

    private function mapPayload(array $p): array
    {
        // Inovio uses cust_id (no separate trans_id on rebill).
        return array_filter([
            'amount'  => $p['amount'] ?? null,
            'mid_id'  => $p['mid_id'] ?? null,
            'cust_id' => $p['member_id'] ?? null,
            'udf_1'   => $p['udf_1'] ?? null,
            'udf_2'   => $p['udf_2'] ?? null,
            'country' => $p['country'] ?? null,
            'ip'      => $p['ip'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** Stored-card charge payload (doCharge). */
    private function mapChargePayload(array $p): array
    {
        return array_filter([
            'amount'      => $p['amount'] ?? null,
            'mid_id'      => $p['mid_id'] ?? null,
            'cust_id'     => $p['member_id'] ?? null,
            'card_number' => $p['card_number'] ?? null,
            'cvv'         => $p['cvv'] ?? null,
            'card_exp'    => $p['card_exp'] ?? null,
            'udf_1'       => $p['udf_1'] ?? null,
            'udf_2'       => $p['udf_2'] ?? null,
            'udf_3'       => $p['udf_3'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function result($approvedFlag): GatewayResult
    {
        $raw  = (array) ($this->client->response ?? []);
        $code = (string) ($raw['response_code'] ?? '');

        return ((int) $approvedFlag === 1)
            ? GatewayResult::approved($raw['transactionid'] ?? null, $raw)
            : GatewayResult::declined($this->normaliseDeclineCode($code), $raw['responsetext'] ?? null, $raw['transactionid'] ?? null, $raw);
    }
}
