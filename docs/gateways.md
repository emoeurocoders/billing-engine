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
| `ip` / `country` / `device` | `ip` / `country` / `device` | `ip` / `country` |

`buildPayload()` (a handler hook) emits the canonical keys; the adapter's `mapPayload()`
drops/renames as needed. To add a gateway-specific field, override `buildPayload()` in a
vertical handler and read it in a custom adapter. `ip` / `country` / `device` come from the
schedule row's seeded `meta`.

### Stored-card ("AZ") charge payload

When a stored card resolves (see [the AZ path](#the-az-stored-card-path)), the charge payload
carries the extra card + context keys the gateway `doCharge` path needs:

| Canonical key | Meaning |
|---|---|
| `card_number` / `cvv` / `card_exp` | decrypted PAN, CVV, `MMYY` expiry |
| `udf_3` | tag for the stored-card path (default `AZ`) |
| `billing` | `setBilling()` fields (`uid`, `email`, `first_name`, `last_name`, `zip`, `country`, `ip`, `device`) |
| `order` | `setOrder()` fields (`order_id`, `order_desc`) |

`NmiGateway::charge()` calls `setOrder()` + `setBilling()` (when present) and then `doCharge()`
via `mapChargePayload()` — mirroring the legacy AZ block exactly.

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

Each gateway maps its raw codes to the canonical set the rest of the system uses. `NmiGateway`
ships the **complete** legacy `NMIBilling::$response_codes` (all 35 entries) as its built-in
`DEFAULT_MAP`, so stored `decline_code`s match legacy out of the box. `normaliseDeclineCode()`
merges any config overrides on top and passes unmapped codes through unchanged (legacy
behaviour):

```php
return array_replace(self::DEFAULT_MAP, config('billing-engine.gateway.code_maps.nmi', []))[$raw] ?? $raw;
```

Add or change entries per driver in config — no package edit needed:

```php
'gateway' => [
    'code_maps' => [
        'nmi'    => ['470' => '104'],   // additions / overrides merged over DEFAULT_MAP
        'inovio' => ['610' => '105'],
    ],
],
```

`InovioGateway` ships only the known essentials (`100 → 0`, `600 → 106`); supply the full
Inovio map via `code_maps.inovio` when an Inovio vertical migrates.

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
glue in the adapter, not the handler. `NmiGateway::charge()` already does this for the AZ
path from the payload's `order` / `billing` keys.

## The AZ (stored-card) path

Some members are billed from a **stored card** (decrypted PAN + CVV) via `doCharge`, not a
token `doRebill` — the legacy `getAzKey()` flow. The engine models this with a small seam:

- **`CardVault` contract** — `resolve(BillingContext): ?CardData`. Returns the member's card
  or `null`. The package ships **`NullCardVault`** (always `null` → token rebill) and
  **`AbstractTokenCardVault`**, a base that reads the token row from a **config-driven table**
  (`az.tokens` — different per vertical) so a vertical's vault only implements `decrypt()`.
  Sports binds **`SportsCardVault extends AbstractTokenCardVault`** (Vault + Crypt decrypt +
  ledger exp/billing — see `examples/SportsCardVault.php`). The token **table name is config,
  not a hardcoded model**; the vault/decrypt specifics stay in the app.
- **`CardData`** — `cardNumber`, `cvv`, `cardExp` (`MMYY`), `billing[]`, `meta[]`.
- **`BillingHandler::charge()`** — the faithful `if ($az) { doCharge } else { doRebill }`:
  resolves a card, and if present charges it (folding in `udf_3` + order + billing), else
  rebills the token. Runs for every sticky type (`rebill`, crosses, the settles rebill).

Controlled by the `az` config block:

```php
'az' => [
    'enabled'    => env('BILLING_AZ_ENABLED', true), // master switch (off → always token rebill)
    'udf_3'      => 'AZ',
    'order_desc' => null,                             // null → uppercased vertical
],
```

Turn `az.enabled` off (or simply don't bind a `CardVault`) for any vertical without stored
cards. See [configuration.md](configuration.md#az) and
[handlers.md](handlers.md#the-charge-hook--token-rebill-vs-stored-card).
