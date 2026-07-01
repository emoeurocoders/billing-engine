# Events

The engine emits five lifecycle events so verticals can attach side-effects (receipts,
cross-sell creation, telemetry, alerts) **without** modifying the billing flow. Keeping
side-effects in listeners is what lets the core `handle()` stay small and uniform.

## The events

| Event | Dispatched when | Payload |
|---|---|---|
| `BillingAttempting` | at the start of `handle()`, before guards | `ctx` |
| `BillingSucceeded` | charge approved (default `onApproved`) | `ctx`, `mid`, `result` |
| `BillingDeclined` | charge declined (default `onDeclined`) | `ctx`, `mid`, `result` |
| `BillingSkipped` | row deferred to next cycle (guard SKIP / no MID) | `ctx`, `reason` |
| `BillingDead` | row permanently stopped (guard DEAD / cancel) | `ctx`, `reason` |
| `BillingSteppedDown` | a declined attempt is scheduled on a step-down rung | `ctx`, `plan` |

All live in `Omni\BillingEngine\Events`. `ctx` is the `BillingContext` (member, type,
amount, row); `result` is the normalised `GatewayResult`; `mid` is the `MidDecision`.

## Listening

Register listeners however the app prefers — `EventServiceProvider`, `Event::listen`, or
closures in a service provider:

```php
use Omni\BillingEngine\Events\BillingSucceeded;
use Omni\BillingEngine\Events\BillingDeclined;

Event::listen(BillingSucceeded::class, function (BillingSucceeded $e) {
    // receipt
    Receipt::sendRebill($e->ctx->memberId(), $e->result->transactionId, $e->ctx->amount());

    // cross-sell scheduling (the legacy addCross)
    CrossScheduler::queueFor($e->ctx->memberId(), $e->mid->descriptor);
});

Event::listen(BillingDeclined::class, function (BillingDeclined $e) {
    Telemetry::declined($e->ctx->billingType, $e->result->responseCode);
});

Event::listen(BillingDead::class, function (BillingDead $e) {
    Log::info("billing dead: {$e->ctx->memberId()} ({$e->reason})");
});
```

## Notes

- Listeners run **inside the job** (synchronously) by default. For heavy side-effects, make
  the listener itself queued (`ShouldQueue`) so it doesn't slow the billing job.
- A listener throwing will bubble into the job and can trigger a retry. Guard side-effect
  listeners with their own try/catch if they must not affect billing outcome.
- The default `onApproved`/`onDeclined` only emit the event. If a vertical overrides those
  hooks and wants the events too, call `parent::onApproved(...)`.
- `BillingAttempting` is useful for per-attempt logging/metrics and for last-moment vetoes
  implemented as a guard (prefer a guard over mutating state in this listener).
