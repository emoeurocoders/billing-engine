<?php

namespace Omni\BillingEngine\Gateways;

use Omni\BillingEngine\Contracts\GatewayClient;
use Omni\BillingEngine\Support\GatewayResult;

/**
 * Adapter over the app's existing NMI library (App\Library\NMI). The package
 * doesn't ship the gateway SDK — the app injects its configured client, and
 * this class translates the canonical payload + normalises the response.
 *
 * Bind in the app:
 *   $this->app->bind(GatewayClient::class, fn() => new NmiGateway(new \App\Library\NMI(...)));
 */
class NmiGateway implements GatewayClient
{
    /** NMI response_code → canonical decline code (from NMIBilling::$response_codes). */
    private const CODE_MAP = [
        '100' => '0', '200' => '157', '201' => '104', '202' => '105', '203' => '105',
        '204' => '165', '223' => '107', '225' => '106', '250' => '109', '440' => '154',
        '410' => '407', // extend from config as needed
    ];

    /** @param object $client the app's NMI library instance (doRebill/doCharge/doCapture). */
    public function __construct(private object $client) {}

    public function rebill(array $payload): GatewayResult
    {
        $code = $this->client->doRebill($this->mapPayload($payload));
        return $this->result($code);
    }

    public function charge(array $payload): GatewayResult
    {
        // Stored-card ("AZ") path: legacy set order + billing before doCharge.
        if (!empty($payload['order']) && method_exists($this->client, 'setOrder')) {
            $this->client->setOrder(array_filter([
                'order_id'   => $payload['order']['order_id'] ?? time(),
                'order_desc' => $payload['order']['order_desc'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if (!empty($payload['billing']) && method_exists($this->client, 'setBilling')) {
            $this->client->setBilling($payload['billing']);
        }

        $code = $this->client->doCharge($this->mapChargePayload($payload));
        return $this->result($code);
    }

    public function capture(string $transactionRef): GatewayResult
    {
        $code = $this->client->doCapture($transactionRef);
        return $this->result($code);
    }

    public function normaliseDeclineCode(?string $rawCode): ?string
    {
        return self::CODE_MAP[$rawCode] ?? $rawCode;
    }

    /** Token rebill payload (doRebill). */
    private function mapPayload(array $p): array
    {
        // NMI uses uid / trans_id.
        return array_filter([
            'amount'     => $p['amount'] ?? null,
            'mid_id'     => $p['mid_id'] ?? null,
            'uid'        => $p['member_id'] ?? null,
            'trans_id'   => $p['source_tr_id'] ?? null,
            'descriptor' => $p['descriptor'] ?? null,
            'udf_1'      => $p['udf_1'] ?? null,
            'udf_2'      => $p['udf_2'] ?? null,
            // Risk/routing fields the legacy doRebill sent.
            'ip'         => $p['ip'] ?? null,
            'country'    => $p['country'] ?? null,
            'device'     => $p['device'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** Stored-card charge payload (doCharge) — real PAN/cvv/exp + udf_3=AZ. */
    private function mapChargePayload(array $p): array
    {
        return array_filter([
            'amount'      => $p['amount'] ?? null,
            'mid_id'      => $p['mid_id'] ?? null,
            'card_number' => $p['card_number'] ?? null,
            'cvv'         => $p['cvv'] ?? null,
            'card_exp'    => $p['card_exp'] ?? null,
            'descriptor'  => $p['descriptor'] ?? null,
            'udf_1'       => $p['udf_1'] ?? null,
            'udf_2'       => $p['udf_2'] ?? null,
            'udf_3'       => $p['udf_3'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function result($approvedFlag): GatewayResult
    {
        $resp = $this->client->response ?? [];
        $raw  = (array) $resp;
        $code = (string) ($raw['response_code'] ?? '');

        return ((int) $approvedFlag === 1)
            ? GatewayResult::approved($raw['transactionid'] ?? null, $raw)
            : GatewayResult::declined($this->normaliseDeclineCode($code), $raw['responsetext'] ?? null, $raw['transactionid'] ?? null, $raw);
    }
}
