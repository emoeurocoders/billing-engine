# Handlers

A **handler** owns the billing flow for one `billing_type`. The base class
`BillingHandler` is a **template method**: `handle()` is `final` (the sequence is fixed)
and each step is a `protected` hook a subclass can override. This single class replaces
the ~1,000 duplicated lines across `rebillCC/PP/Settles/Cross1/Cross2`.

## The flow (`BillingHandler::handle()`)

```php
final public function handle(BillingContext $ctx): void
{
    Event::dispatch(new BillingAttempting($ctx));

    // 1. Guards (config-ordered pipeline) → SKIP defers, DEAD stops
    $verdict = $this->guards->run($ctx, $ctx->typeConfig['guards'] ?? []);
    if (!$verdict->passed()) { $this->finishGuarded($ctx, $verdict); return; }

    // 2. MID selection (sticky vs rotation)
    $mid = $this->resolveMid($ctx);
    if (!$mid) { $this->defer($ctx, Reasons::NO_MID); return; } // 'no_usable_mid', 1-day retry
    $ctx->mid = $mid;

    // 3. Charge
    $result = $this->charge($ctx, $this->buildPayload($ctx, $mid));
    $ctx->result = $result;

    // 4. Record (MID state + attempt log)
    $this->mids->recordResult($mid, $result->approved, $this->resultDetails($result));
    $this->log->record($ctx, $result->approved, $result->responseCode, $result->transactionId);

    // 5. Outcome hooks + next due date
    $result->approved ? $this->onApproved($ctx, $mid, $result)
                      : $this->onDeclined($ctx, $mid, $result);
    $this->scheduleNext($ctx, $result);
}
```

`handle()` being final guarantees every type runs guards, records results, and reschedules
— a vertical can change *what* a step does, never *whether* it happens.

## Overridable hooks

| Hook | Default | Override when… |
|---|---|---|
| `resolveMid(ctx)` | sticky if `selection=sticky`, else rotation | custom pool/selection logic |
| `buildPayload(ctx, mid)` | canonical payload (amount, mid, member, descriptor, UDFs) | extra fields (AZ token, custom UDFs) |
| `charge(ctx, payload)` | stored-card `charge()` if a card resolves, else `gateway->rebill()` | force one path, or `capture()` (settle) |
| `resolveCard(ctx)` | the bound `CardVault` (default: none) | custom stored-card lookup |
| `onApproved(ctx, mid, r)` | emit `BillingSucceeded` | post-approval side-effects in-handler |
| `onDeclined(ctx, mid, r)` | emit `BillingDeclined` | post-decline side-effects |
| `scheduleNext(ctx, r)` | approve → next cycle; decline → step-down rung if one applies, else defer/dead | custom cadence |
| `applyStepDown(ctx, plan)` | set amount/MID/`next_action_at` for the next rung | custom rung effects |

Terminal helpers (`finishGuarded`, `defer`, `resultDetails`) are also overridable but rarely
need to be.

## The `charge` hook — token rebill vs stored-card

`charge()` is the faithful port of the legacy `if ($az) { doCharge } else { doRebill }`:

```php
protected function charge(BillingContext $ctx, array $payload): GatewayResult
{
    if ($card = $this->resolveCard($ctx)) {                 // CardVault → stored card?
        return $this->gateway->charge($this->withCard($ctx, $payload, $card));
    }
    return $this->gateway->rebill($payload);                // else token rebill
}
```

`resolveCard()` asks the bound `CardVault` (default `NullCardVault` → always `null`, so the
token path) and honours the `az.enabled` master switch. When a card resolves, `withCard()`
folds the PAN/CVV/exp, `udf_3`, and the `order`/`billing` context into the payload, and the
adapter's `charge()` runs `setOrder`/`setBilling`/`doCharge`. This applies to every sticky
type (`rebill`, crosses, the settles rebill). Full detail:
[gateways.md](gateways.md#the-az-stored-card-path).

## Shipped handlers

### `RebillHandler` (`rebill`)
Empty subclass — the base flow *is* the rebill flow. Sticky MID, next cycle on approval,
and the token-rebill-or-stored-card `charge()` above. Covers `rebillCC` and `rebillPP` (card
type/amount come from the row, UDFs from config); with a bound `CardVault` it also covers the
AZ `doCharge` path those methods had.

### `CrossHandler` (`cross1`, `cross2`)
Structurally identical to `rebill` — an empty subclass. Its only differences (a step-down
ladder and "cancel when the ladder is exhausted") are **config**: the `stepdowns` block with
`on_exhausted => 'dead'`. The base handler's `scheduleNext`/`applyStepDown` + the
`StepDownPlanner` do the work. A vertical needing different step-down *logic* (not numbers)
subclasses and overrides `applyStepDown` (see `examples/CustomCrossHandler.php`). Step-downs
are documented fully in [step-downs.md](step-downs.md).

### `ConvertHandler` (`convert`)
Forces **rotation** MID selection (`resolveRotationMid`) and uses `charge()` when explicit
card data is present, else `rebill()`. Backs `convertInitials` / `convertPP`. With the
mid-balancer adapter, rotation is the load-balancer's `selectMid`.

### `SettleHandler` (`settle`)
Overrides `charge()` to call `capture(source_tr_id)` — it settles an existing
authorization rather than charging anew — and `scheduleNext()` to be one-and-done (capture
succeeds → `done`, else retry tomorrow). Backs `settleAuths`.

## Registry

`BillingHandlerRegistry` maps `billing_type → handler class`, seeded from
`config('billing-engine.types')`. A vertical overrides one type in a service provider:

```php
$this->app->make(BillingHandlerRegistry::class)
    ->bind('cross2', \App\Billing\CustomCrossHandler::class);
```

Handlers are container-resolved, so their constructor dependencies (`MidResolver`,
`GatewayClient`, `AttemptLogger`, `GuardRunner`) are injected automatically.

## The context object

Every hook receives a `BillingContext`, assembled once per job:

- `row` — the `BillingSchedule` model (mutate + save here)
- `vertical`, `billingType`, `typeConfig` — resolved config for this type
- `mid`, `result` — filled in as the pipeline runs
- helpers: `memberId()`, `amount()`, `cardType()`, `cycle()`, `logTable()`, `selection()`

Note: `MidCap` guard caches its resolved MID onto `$ctx->mid`; the base `resolveMid` will
re-resolve unless a handler chooses to reuse `$ctx->mid` first. (A small optimization a
vertical can adopt if MID lookups are costly.)
