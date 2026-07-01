# Extending per vertical

The package is one shared codebase for six verticals. Customisation follows one rule:

> **Match each kind of difference to the lightest mechanism that expresses it.**

A vertical reaches for a heavier mechanism only when the lighter layer can't express the
difference. Override cost scales with how unusual the customization is.

| Layer | Handles | Mechanism | Cost |
|---|---|---|---|
| **Config** | amounts, UDFs, cycle, MID table, stepdown ladder, decline map, guard list | edit `config/billing-engine.php` | array edit |
| **Handlers** | flow/behaviour differences | subclass a handler hook + `BillingHandlerRegistry::bind()` | one class + one bind |
| **Guards** | new pre-charge rules | implement `BillingGuard`, register, add its key | one class + config |
| **Events** | side-effects (receipts, cross-sell, telemetry) | listen for a billing event | one listener |
| **Contracts** | infra swaps (MID source, gateway, attempt log) | bind an implementation | one bind |

## 1. Config — most differences

The cross1/cross2/fit divergence (amounts, MID pool table, stepdown ladders, which UDFs,
which log table) is **pure config**. Example: a vertical whose cross ladder is 19.87→9.87:

```php
'types' => ['cross2' => ['stepdown' => [['from' => 19.87, 'to' => 9.87]]]],
```

No code. Different MID source table? `'mids' => ['table' => 'pdf_mids']`. Different gateway?
`'gateway' => ['driver' => 'inovio']`.

## 2. Handlers — flow differences

When behaviour (not just data) differs, subclass the shipped handler and override only the
hook that changes. Example — fit cross cancels after 2 declines and steps 19.87→9.87 with
custom logic:

```php
class CustomCrossHandler extends CrossHandler
{
    protected function scheduleNext(BillingContext $ctx, GatewayResult $r): void
    {
        if (!$r->approved && abs($ctx->amount() - 19.87) < 0.001) $ctx->row->amount = 9.87;
        $ctx->typeConfig['max_attempts'] = 2;
        parent::scheduleNext($ctx, $r);
    }
}
```

Register in a service provider:

```php
$this->app->make(BillingHandlerRegistry::class)->bind('cross2', CustomCrossHandler::class);
```

Available hooks: `resolveMid`, `buildPayload`, `charge`, `onApproved`, `onDeclined`,
`scheduleNext` (see [handlers.md](handlers.md)).

## 3. Guards — new rules

Implement `BillingGuard`, register it, add its key to the type's `guards`:

```php
class ChaseBinGuard implements BillingGuard {
    public function key(): string { return 'chase_bin'; }
    public function check(BillingContext $ctx): GuardResult { /* pass | skip | dead */ }
}
// service provider
$this->app->make(GuardRunner::class)->register('chase_bin', ChaseBinGuard::class);
// config: types.rebill.guards => [..., 'chase_bin']
```

See [guards.md](guards.md).

## 4. Events — side-effects

Keep receipts, cross-sell inserts (`addCross`), analytics, alerts out of the billing flow —
listen for them instead:

```php
Event::listen(BillingSucceeded::class, function (BillingSucceeded $e) {
    Receipt::sendFor($e->ctx->memberId(), $e->result->transactionId);
});
Event::listen(BillingDeclined::class, fn ($e) => Telemetry::declined($e->ctx, $e->result));
```

See [events.md](events.md).

## 5. Contracts — infra swaps

Swap whole subsystems by binding a different implementation:

```php
$this->app->singleton(MidResolver::class,  SportsMidBalancerAdapter::class); // use mid-balancer
$this->app->singleton(AttemptLogger::class, MyCustomLogger::class);          // different log store
$this->app->bind(GatewayClient::class, fn () => new MyThirdGateway(...));     // new processor
```

See [mid-resolution.md](mid-resolution.md) and [gateways.md](gateways.md).

## App-bound closures (the SQL seam)

Guards and the seeder that need vertical-specific SQL resolve **app-bound closures** so the
package never imports a vertical's models:

| Binding | Signature | Used by |
|---|---|---|
| `billing.gatewayClient` | `() => object` | gateway adapter |
| `billing.sameDayCheck` | `(memberId) => bool` | `SameDay` guard |
| `billing.negativeDb` | `(memberId) => ?string` | `NegativeDb` guard |
| `billing.declineCount` | `(memberId, type) => int` | `MaxDeclines` guard |
| `billing.conversionRebill` | `(memberId) => bool` | `ConversionRebill` guard |
| `billing.seedSource` | `(vertical, types) => iterable` | `billing:seed-schedule` |

All are shown together in `examples/AppServiceProviderWiring.php`.

## Decision guide

```
Is the difference just a value (amount / table / UDF / ladder)?      → config
Does the billing FLOW change for one step?                           → handler hook
Is it a new yes/no rule before charging?                             → guard
Is it something that happens AFTER a result (notify, log, upsell)?   → event listener
Are you swapping where MIDs/gateway/logs come from?                  → contract binding
```
