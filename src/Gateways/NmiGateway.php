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
        $code = $this->client->doCharge($this->mapPayload($payload));
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
