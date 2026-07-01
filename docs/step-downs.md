# Step-downs

A **step-down** retries a declined attempt at a (usually lower) amount after a delay,
triggered by specific decline codes — and sometimes on a different MID. This covers the
legacy `rebillStepDownCC`, `rebillStepDownMatching`, `rebillStepDownCross1/2`, and the
conversion `stepDownCC` crons. In the engine they are **not** separate crons: they are part
of the schedule row's decline lifecycle, driven entirely by config.

## How it works

When a charge declines, `BillingHandler::scheduleNext()` asks the `StepDownPlanner` whether
a rung applies:

```
decline → StepDownPlanner.plan(ctx, result)
            │
   ┌── rung matched ──┐         └── no rung ──┐
   ▼                  ▼                        ▼
 set amount,   schedule retry         step>0 & on_exhausted=dead → dead
 step++,       at now + after_days    else → defer to next cycle
 mid_strategy  (status = pending)
```

The dispatcher re-claims the row when `next_action_at` arrives and bills it again at the new
amount/MID. The `step` column tracks which rung the row is on (0 = full price).

Because a step-down retry happens **within the same cycle**, the `AlreadyAttempted` and
`SameDay` guards explicitly **exempt rows with `step > 0`** — otherwise they'd block the
intentional retry.

## Configuring a ladder

Per `billing_type`, under `stepdowns`:

```php
'stepdowns' => [
    'max_steps'        => 2,                       // rungs before the ladder stops
    'on_exhausted'     => 'defer',                 // defer (wait next cycle) | dead (cancel)
    'country_excluded' => ['JM', 'PG', 'RE', 'BS'],// never step down for these countries
    'rules' => [
        // matched top-down; first match wins; gated by the row's current step
        ['step' => 0, 'card_type' => 'cc', 'codes' => ['104','105','109','157'], 'after_days' => 8, 'to' => 19.55, 'mid' => 'sticky'],
        ['step' => 0, 'card_type' => 'cc', 'codes' => ['154','407'],             'after_days' => 3, 'to' => null,  'mid' => 'sticky'],
        ['step' => 1, 'card_type' => 'cc', 'codes' => ['105','109','157'],       'after_days' => 3, 'to' => 19.55, 'mid' => 'match'],
    ],
],
```

### Rule fields

| Field | Meaning |
|---|---|
| `step` | the rung this rule applies at (`0` = first decline). Omit to match any step. |
| `card_type` | `cc`, `pp`, or `any` |
| `codes` | canonical decline codes that trigger this rung |
| `after_days` | delay before the retry becomes due |
| `to` | new amount; `null` = keep the current amount |
| `mid` | MID strategy for the retry: `sticky` (same), `match` (different MID, same descriptor), `new` (rotation) |

### `max_steps` and `on_exhausted`

- `max_steps` caps the ladder — once `step >= max_steps`, the planner returns nothing.
- When no rung applies and the row already stepped (`step > 0`):
  - `on_exhausted => 'defer'` → wait for the next cycle (rebills, crosses by default)
  - `on_exhausted => 'dead'` → cancel the subscription line (conversions)

## MID strategies

The legacy code varied the MID by decline code (105 → keep sticky; others → new/matching).
That's expressed via the `mid` field:

| `mid` | Resolver call | Use |
|---|---|---|
| `sticky` | `resolveStickyMid` | retry on the same MID (e.g. insufficient funds — code 105) |
| `match` | `resolveMatchingMid(originalMidId)` | a different MID sharing the descriptor (the legacy "matching" step) |
| `new` | `resolveRotationMid` | a fresh load-balanced MID |

`resolveMatchingMid` is part of the `MidResolver` contract — the default
`DirectMidResolver` matches by descriptor in the configured mids table; the mid-balancer
adapter uses `MidBalancer::selectMatchingMids`.

## Sports ladders (shipped defaults)

| Type | card | codes | wait | amount | MID | notes |
|---|---|---|---|---|---|---|
| `rebill` | cc | 104,105,109,157 | 8d | →19.55 | sticky | step 0 |
| `rebill` | cc | 154,407 | 3d | same | sticky | step 0 |
| `rebill` | pp | 105 | 8d | →19.55 | sticky | step 0 |
| `rebill` | cc | 105,109,157 | 3d | →19.55 | match | step 1 (matching) |
| `rebill` | cc | 154,407 | 3d | same | match | step 1 |
| `cross1` | any | 105,109,157 | 10d | →19.90 | sticky | 1 step |
| `cross2` | any | 104,105,109,157 | 10d | →19.79 | sticky | 1 step (sports; fit uses 19.87) |
| `convert` | cc | 109,157 | 7d | →19.55 | new | `on_exhausted=dead` |
| `convert` | cc | 154,407 | 3d | →19.55 | new | — |

Fit/other verticals override the amounts in their own config (e.g. cross2 `28.87 → 19.87`).

## What's not here (yet)

- **`isStepDown()` / live auth step-down vs auth-retry at signup.** That decision lives in
  the *initial charge* (auth) flow, not the scheduled billing cycle, and belongs with the
  conversion/initial-charge work. Its amount/code table (34.55 / 19.55 thresholds; codes
  201/204 step down, 250/252/253/200 auth-retry) is captured for that phase.
- **OmniCross-driven cross *initial* step-downs** (`stepDownCross1/2`) — these belong to the
  cross initial-charge flow; the cross *rebill* step-downs are covered above.

## Events

Each scheduled rung fires `BillingSteppedDown($ctx, $plan)` — listen for it to report ladder
progression or alert on members reaching the final rung.
