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
    /**
     * NMI response_code → canonical decline code. The COMPLETE legacy
     * NMIBilling::$response_codes map, shipped in code so it's faithful out of
     * the box. Per-driver additions/overrides come from
     * `billing-engine.gateway.code_maps.nmi` and are merged on top — see map().
     */
    private const DEFAULT_MAP = [
        '0'   => '0',   // refund approved
        '100' => '0',   // approved
        '200' => '157', // declined by processor
        '201' => '104', // do not honor
        '202' => '105', // insufficient funds
        '203' => '105', // over limit
        '204' => '165', // transaction not allowed
        '220' => '159', // incorrect payment information
        '221' => '221', // no such card issuer
        '222' => '111', // no card number on file with issuer
        '223' => '107', // expired card
        '224' => '404', // invalid expiration date
        '225' => '106', // invalid CVV
        '226' => '129', // invalid PIN
        '240' => '108', // call issuer
        '250' => '109', // pick up card
        '251' => '164', // lost card
        '252' => '164', // stolen card
        '253' => '109', // fraudulent card
        '260' => '260', // declined, further instructions available
        '261' => '123', // declined, stop all recurring payments
        '262' => '262', // declined, stop this recurring program
        '263' => '263', // declined, update cardholder data available
        '264' => '264', // declined, retry in a few days
        '300' => '300', // rejected by gateway
        '400' => '113', // transaction error returned by processor
        '410' => '407', // invalid merchant configuration
        '411' => '113', // merchant account inactive
        '420' => '311', // communication error
        '421' => '312', // communication error with issuer
        '430' => '212', // duplicate transaction at processor
        '440' => '154', // processor format error
        '441' => '165', // invalid transaction information
        '460' => '460', // processor feature not available
        '461' => '156', // unsupported card type
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
        if ($rawCode === null) {
            return null;
        }

        return $this->map()[$rawCode] ?? $rawCode; // unmapped → passthrough (legacy behaviour)
    }

    /** Built-in map with config overrides merged on top. */
    private function map(): array
    {
        return array_replace(self::DEFAULT_MAP, (array) config('billing-engine.gateway.code_maps.nmi', []));
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
