# MID resolution

Picking which MID to bill on — and recording the outcome against MID state — is behind the
`MidResolver` contract. This is the seam that lets the engine stay standalone while sports
still uses the full mid-balancer intelligence.

## The contract

```php
interface MidResolver
{
    public function resolveStickyMid(string $midId, BillingContext $ctx): ?MidDecision;
    public function resolveRotationMid(BillingContext $ctx): ?MidDecision;
    public function recordResult(MidDecision $mid, bool $approved, array $details = []): void;
}
```

- **sticky** — rebills/crosses/settles bill the member's *original* MID, honoring any
  `redirect_mid` and validating it's still usable. Returns `null` → caller defers the row.
- **rotation** — conversions/initials pick a MID by load-balancing.
- **recordResult** — feed the outcome into whatever MID state the resolver maintains.

`MidDecision` carries the chosen `midId`, `descriptor`, `bank`, and an opaque `state` handle
(e.g. a mid-balancer `RebillDailyStats` model) the resolver uses in `recordResult`.

## Configurable source table

Verticals read MID config from **different, same-shape tables**, all on the
`bigentertainment` connection:

| Vertical | `mids.table` |
|---|---|
| sports | `mids` |
| pdf | `pdf_mids` |
| manuals | `manuals_mids` |

```php
'mids' => [
    'connection' => env('BILLING_MIDS_CONNECTION', null), // null = default (bigentertainment)
    'table'      => env('BILLING_MIDS_TABLE', 'mids'),
],
```

These tables share every column the sticky path needs (`mid_id, redirect_mid, descriptor,
active, status, cross1, cross2, daily_caps, cc_cards, pp_cards, rebills_only`). `mids` has a
few extras the rebill path doesn't use, so **no per-table column map is needed** — the
default resolver null-coalesces the optional columns.

### The engine reads the SOURCE `mids` table — not the legacy `sports_mids` copy

The legacy rebill code selected from `omnistats.sports_mids`, a copy of the source `mids`
table kept in sync by the `UpdateMids` command (`processor_id=1`, with the MID's `stack`
mapped onto `cross1`/`cross2`). The engine reads the **source** `mids` table directly, which
is equivalent — so the `UpdateMids` sync can be retired per vertical after cutover.

Crucially, the runtime counter columns that only exist on `sports_mids` — `count`,
`count_declines`, `hard_cap`, `weekly_sales`, `daily_volume` — are **deliberately not
replicated**. They were the legacy per-MID capping/health mechanism, and they are exactly
what the mid-balancer's `RebillDailyStats` replaces. So:

- **MID config / selection** → the source `mids` table (`DirectMidResolver`, or the sports
  adapter for balancer-backed selection).
- **MID caps + decline health** (legacy `count >= hard_cap`, `count_declines < max_declines`)
  → the **mid-balancer** (`RebillDailyStats` + `MidBalancer::recordRebillResult`), via the
  sports adapter — *not* a `sports_mids` column read/write.

There is nothing to wire for the old `sports_mids` counters.

## Default: `DirectMidResolver`

Reads the configured table directly. Works with **no mid-balancer present**.

- `resolveStickyMid` — loads the MID, follows `redirect_mid` to a live MID, and returns it
  only if `active=1` and status isn't `killed`/`high_declines`; otherwise `null`.
- `resolveRotationMid` — minimal rotation: active, rebills-eligible, lowest `daily_order`,
  randomized.
- `recordResult` — no-op by default (the schedule row + attempt log already capture
  outcomes). Override to feed external state.

Any vertical that doesn't run mid-balancer can use this as-is.

## Adapter: mid-balancer (sports)

Sports binds its own resolver that delegates to the mid-balancer package, so it keeps the
load-balancer's sticky validation, daily-stats, and decline tracking — while the engine
itself has **zero dependency** on mid-balancer:

```php
class SportsMidBalancerAdapter implements MidResolver
{
    public function resolveStickyMid(string $midId, BillingContext $ctx): ?MidDecision
    {
        $mid = /* read mids table, honor redirect, validate active/status */;
        $stats = MidBalancer::getOrCreateRebillDailyStats($mid->mid_id, $ctx->vertical, ...);
        return new MidDecision($mid->mid_id, $mid->descriptor, $mid->bank, state: $stats);
    }

    public function resolveRotationMid(BillingContext $ctx): ?MidDecision
    {
        $stats = MidBalancer::selectMid($ctx->vertical, ['amount' => $ctx->amount()]);
        return $stats ? new MidDecision($stats->mid_id, ..., state: $stats) : null;
    }

    public function recordResult(MidDecision $mid, bool $approved, array $details = []): void
    {
        MidBalancer::recordRebillResult(mid: $mid->state, approved: $approved,
            declineCode: $details['declineCode'] ?? null, /* ... */);
    }
}
```

Bind it:

```php
$this->app->singleton(MidResolver::class, \App\Billing\SportsMidBalancerAdapter::class);
```

Full file: `examples/SportsMidBalancerAdapter.php`.

## Sticky vs rotation selection

The handler decides which to call based on the type's `selection` config:

```php
protected function resolveMid(BillingContext $ctx): ?MidDecision
{
    return $ctx->selection() === 'rotation'
        ? $this->mids->resolveRotationMid($ctx)
        : $this->mids->resolveStickyMid((string) $ctx->midId(), $ctx);
}
```

`ConvertHandler` forces rotation regardless. Override `resolveMid` for anything bespoke.

## When no MID resolves — a **1-day** retry, not a full cycle

If resolution returns `null` (every candidate closed, no live `redirect_mid` yet) the row is
deferred with the canonical reason **`no_usable_mid`** (`Reasons::NO_MID`) and `next_action_at`
is rescheduled to **now + `no_mid_retry_days`** (default **1 day**) — *not* the full `cycle_days`.
This is deliberate: a no-MID miss is transient, and redirects are frequently added daily, so the
member should be retried the next day once a live/redirect MID exists.

The same condition is detected in **two** places and both use that one reason + short retry: the
`mid_cap` guard (early, for sticky selection) and the handler's own `resolveMid` fallback. The
routing lives in `BillingHandler::defer()`, which picks the short interval whenever the reason is
`no_usable_mid` and the full cycle for every other skip.

```php
'no_mid_retry_days' => 1,   // global; override per type via types.{type}.no_mid_retry_days
```

Contrast with a **guard** skip (`same_day`, `already_attempted`, `negative_db`, …): those defer a
full `cycle_days`, because the condition won't clear tomorrow. Only the no-MID path uses the short
interval. Grep production logs for `reason=no_usable_mid` to see the cohort waiting on a MID.

## Recording outcomes

After every charge (approved **and** declined), `handle()` calls `MidResolver::recordResult(...)`
with normalised details, which the sports adapter forwards to `MidBalancer::recordRebillResult()`
— the same call the legacy `rebillCC`/`rebillPP` made, so **reporting stays intact**. The details:

| detail | value | legacy parity |
|---|---|---|
| `declineCode` | the **raw** gateway `response_code` | legacy passed the raw code, not the canonical |
| `canonicalCode` | the normalised code | (extra — use if you prefer the mapped value) |
| `bankResponseCode` | `processor_response_code` | ✓ |
| `declineReason` / `transactionId` | as returned | ✓ |
| `vertical` | the member's `udf_1` (from seeded `meta`) | legacy passed `vertical: $udf_1` |

Two things to know:
- **Only the mid-balancer adapter records.** `DirectMidResolver::recordResult` is a **no-op**, so
  a vertical must bind `MidResolver → SportsMidBalancerAdapter` for `recordRebillResult` to fire.
  If reporting looks empty, this binding is the first thing to check.
- The old `sports_mids` `count`/`count_declines`/`daily_volume` columns are intentionally left
  behind — see "[The engine reads the SOURCE `mids` table](#the-engine-reads-the-source-mids-table--not-the-legacy-sports_mids-copy)".
