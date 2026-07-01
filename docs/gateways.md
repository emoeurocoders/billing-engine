# Gateways

Three verticals bill through **NMI**, three through **Inovio**. Both libraries already
expose the same method names (`doCharge`, `doRebill`, `doCapture`), return `1`/`0`, and
populate a `->response` map with `response_code` / `responsetext` / `transactionid`. The
engine hides those behind one contract so handlers never see gateway-specific shapes.

## The contract

```php
interface GatewayClient
{
    public function rebill(array $payload): GatewayResult;    // doRebill
    public function charge(array $payload): GatewayResult;    // doCharge
    public function capture(string $transactionRef): GatewayResult; // doCapture
    public function normaliseDeclineCode(?string $rawCode): ?string;
}
```

## Canonical payload

Handlers build a **canonical** payload; each adapter translates it to its gateway's keys:

| Canonical key | NMI key | Inovio key |
|---|---|---|
| `member_id` | `uid` | `cust_id` |
| `source_tr_id` | `trans_id` | — |
| `amount` | `amount` | `amount` |
| `mid_id` | `mid_id` | `mid_id` |
| `descriptor` | `descriptor` | — |
| `udf_1` / `udf_2` | `udf_1` / `udf_2` | `udf_1` / `udf_2` |

`buildPayload()` (a handler hook) emits the canonical keys; the adapter's `mapPayload()`
drops/renames as needed. To add a gateway-specific field, override `buildPayload()` in a
vertical handler and read it in a custom adapter.

## Normalised result

Every adapter returns a `GatewayResult`:

```php
final class GatewayResult {
    public bool    $approved;
    public ?string $responseCode;     // canonical decline code
    public ?string $responseText;
    public ?string $transactionId;
    public array   $raw;              // the untouched gateway response, for details/debug
}
```

Handlers and the attempt logger only ever read this. `raw` carries the original response
for anything that needs gateway detail (e.g. the mid-balancer adapter pulls
`processor_response_code` from it).

## Decline-code normalisation

Each gateway maps its raw codes to the canonical set the rest of the system uses (the same
map the legacy `NMIBilling::$response_codes` used). `NmiGateway` and `InovioGateway` each
hold a `CODE_MAP`; extend them (or make the map config-driven) as you encounter codes:

```php
// NmiGateway
private const CODE_MAP = ['201' => '104', '202' => '105', '250' => '109', /* ... */];
```

## The adapters wrap the app's library

The package does **not** ship the NMI/Inovio SDK. The app binds its configured library
instance; the service provider wraps it in the right adapter based on
`config('billing-engine.gateway.driver')`:

```php
// app service provider
$this->app->bind('billing.gatewayClient', function () {
    $nmi = new \App\Library\NMI();
    $nmi->setApiKey(config('nmi.api_key'));
    return $nmi;
});
// config: gateway.driver => 'nmi'  (or 'inovio' with App\Library\Inovio)
```

`GatewayClient` is then resolved automatically:

```php
$this->app->singleton(GatewayClient::class, function ($app) {
    $client = $app->make('billing.gatewayClient');
    return config('billing-engine.gateway.driver') === 'inovio'
        ? new InovioGateway($client)
        : new NmiGateway($client);
});
```

## Adding a third gateway

1. Implement `GatewayClient` (translate payload + normalise response).
2. Bind it for `GatewayClient::class` in the app (or extend the service-provider switch).
3. Set `gateway.driver` accordingly.

Nothing in the handlers, guards, or schedule changes.

## Order / billing context for charges

Some gateway calls need order/billing context set before the charge (the legacy code calls
`setOrder()` / `setBilling()`). Do that inside the adapter's `rebill`/`charge` using fields
from the payload, or have the app's bound library instance pre-configured — keeping that
glue in the adapter, not the handler.
